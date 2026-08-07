<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoAddressRepository;
use App\Repos\PdoProjectRepository;
use App\Repos\PdoProjectTypeRepository;
use App\Repos\PdoPropertyRepository;
use InvalidArgumentException;

final class ProjectService
{
    public function __construct(
        private PdoAddressRepository $addresses,
        private PdoPropertyRepository $properties,
        private PdoProjectTypeRepository $projectTypes,
        private PdoProjectRepository $projects
    ) {}

    public function createAddress(array $input): array
    {
        $data = $this->normalizeAddress($input);
        $id = $this->addresses->create($data);
        $created = $this->addresses->findById($id);
        if (!$created) {
            throw new \RuntimeException('Failed to create address');
        }
        return $created;
    }

    public function createProperty(array $input): array
    {
        $addressId = (int)($input['address_id'] ?? 0);
        if ($addressId > 0 && !$this->addresses->findById($addressId)) {
            throw new InvalidArgumentException('valid address_id required');
        }

        $clientId = isset($input['client_id']) && (int)$input['client_id'] > 0 ? (int)$input['client_id'] : null;
        $id = $this->properties->create([
            'address_id' => $addressId > 0 ? $addressId : null,
            'client_id' => $clientId,
            'name' => $this->requiredString($input['name'] ?? null, 'name'),
            'notes' => $this->optionalString($input['notes'] ?? null),
        ]);

        $created = $this->properties->findById($id);
        if (!$created) {
            throw new \RuntimeException('Failed to create property');
        }
        return $created;
    }

    public function createProject(array $input): array
    {
        $propertyId = (int)($input['property_id'] ?? 0);
        if ($propertyId <= 0 || !$this->properties->findById($propertyId)) {
            throw new InvalidArgumentException('valid property_id required');
        }

        $projectTypeId = (int)($input['project_type_id'] ?? 0);
        if ($projectTypeId <= 0 || !$this->projectTypes->findById($projectTypeId)) {
            throw new InvalidArgumentException('valid project_type_id required');
        }

        $status = $this->normalizeStatus($input['status'] ?? 'prospect');
        $experienceKey = $this->normalizeExperienceKey($input['experience_key'] ?? 'concept');
        $id = $this->projects->create([
            'property_id' => $propertyId,
            'project_type_id' => $projectTypeId,
            'name' => $this->optionalString($input['name'] ?? null),
            'status' => $status,
            'experience_key' => $experienceKey,
            'notes' => $this->optionalString($input['notes'] ?? null),
        ]);

        $created = $this->projects->findById($id);
        if (!$created) {
            throw new \RuntimeException('Failed to create project');
        }
        return $created;
    }

    public function listProjectTypes(bool $activeOnly = true): array
    {
        return $this->projectTypes->list($activeOnly);
    }

    private function normalizeAddress(array $input): array
    {
        $street1 = $this->requiredString($input['street_1'] ?? null, 'street_1');
        $city = $this->requiredString($input['city'] ?? null, 'city');
        $state = $this->requiredString($input['state'] ?? null, 'state');
        $postalCode = $this->requiredString($input['postal_code'] ?? null, 'postal_code');
        $countryCode = strtoupper($this->optionalString($input['country_code'] ?? null) ?? 'US');
        if (strlen($countryCode) !== 2) {
            throw new InvalidArgumentException('country_code must be two characters');
        }

        return [
            'street_1' => $street1,
            'street_2' => $this->optionalString($input['street_2'] ?? null),
            'city' => $city,
            'state' => $state,
            'postal_code' => $postalCode,
            'country_code' => $countryCode,
        ];
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = $this->optionalString($value) ?? 'prospect';
        if (!preg_match('/^[a-z][a-z0-9_-]{0,29}$/', $status)) {
            throw new InvalidArgumentException('invalid project status');
        }
        return $status;
    }

    private function normalizeExperienceKey(mixed $value): string
    {
        $experienceKey = $this->optionalString($value) ?? 'concept';
        if (!in_array($experienceKey, ['concept', 'client', 'public', 'painter'], true)) {
            throw new InvalidArgumentException('invalid project experience_key');
        }
        return $experienceKey;
    }

    private function requiredString(mixed $value, string $field): string
    {
        $normalized = $this->optionalString($value);
        if ($normalized === null) {
            throw new InvalidArgumentException($field . ' required');
        }
        return $normalized;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string)$value);
        return $normalized !== '' ? $normalized : null;
    }
}
