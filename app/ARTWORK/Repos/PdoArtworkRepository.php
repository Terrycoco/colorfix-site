<?php
declare(strict_types=1);

namespace App\ARTWORK\Repos;

use InvalidArgumentException;
use PDO;

/**
 * Persistence for photos_artwork metadata. The actual photograph belongs to PHOTOS;
 * palette associations belong to PHOTOS/PALETTES, not this repository.
 *
 * One artwork metadata row per photo_library_id (uq_artwork_photo).
 * Uses the caller's PDO/transaction; never creates or deletes photo_library rows.
 * Authorization and input validation beyond field shape belong to the calling service/endpoint.
 */
final class PdoArtworkRepository
{
    private const FIELDS = [
        'artist', 'artist_last', 'official_title', 'display_title', 'year', 'circa', 'medium',
        'museum', 'source_url', 'historical_notes', 'color_analysis',
    ];

    private const SELECT = 'artwork_id, photo_library_id, artist, artist_last, official_title, '
        . 'display_title, year, circa, medium, museum, source_url, '
        . 'historical_notes, color_analysis, created_at, updated_at';

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $artworkId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM photos_artwork WHERE artwork_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $this->positiveId($artworkId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByPhotoId(int $photoLibraryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM photos_artwork WHERE photo_library_id = :photo_id LIMIT 1'
        );
        $stmt->execute(['photo_id' => $this->positiveId($photoLibraryId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Read-only context lookup; retain missing metadata so ambiguity cannot be hidden. */
    public function findArtworkCandidatesForPhotos(array $photoIds): array
    {
        $photoIds = array_values(array_unique(array_filter(array_map('intval', $photoIds), fn(int $id): bool => $id > 0)));
        if (!$photoIds) { return []; }
        $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
        $artPhoto = "CASE WHEN p.source_type = 'art' THEN p.photo_library_id ELSE p.inspiration_photo_id END";
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT {$artPhoto} AS photo_library_id, a.artwork_id
               FROM photo_library p
               LEFT JOIN photos_artwork a ON a.photo_library_id = {$artPhoto}
              WHERE p.photo_library_id IN ({$placeholders})
                AND (p.source_type = 'art' OR p.inspiration_photo_id IS NOT NULL)"
        );
        $stmt->execute($photoIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Browse existing artwork records (newest first). Read-only, with bounded pagination.
     * This returns artwork fields; callers may join photo_library read-only for thumbnails.
     */
    public function list(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 500 || $offset < 0) {
            throw new InvalidArgumentException('Invalid artwork pagination.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT
            . ' FROM photos_artwork ORDER BY artwork_id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Public gallery: artwork metadata plus PHOTOS-owned, visible image/crop data.
     * This is a bounded, read-only cross-department presentation join.
     */
    public function listPublicGallery(int $limit = 500): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Invalid gallery limit.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.artwork_id, a.photo_library_id, a.artist, a.artist_last, a.official_title,
                    a.display_title, p.rel_path AS photo_url, p.title AS title,
                    pc.crop_x, pc.crop_y, pc.crop_width, pc.crop_height
               FROM photos_artwork a
               JOIN photo_library p ON p.photo_library_id = a.photo_library_id
               LEFT JOIN photo_crops pc
                 ON pc.photo_library_id = p.photo_library_id AND pc.crop_type = 'swatch'
              WHERE p.source_type = 'art'
                AND p.show_in_gallery = 1
                AND COALESCE(p.is_inactive, 0) = 0
                AND COALESCE(p.is_retired, 0) = 0
                AND p.rel_path IS NOT NULL AND p.rel_path <> ''
              ORDER BY a.artwork_id DESC
              LIMIT :max_rows"
        );
        $stmt->bindValue(':max_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Creates metadata linked to an existing photo. Duplicate photo IDs cause a
     * unique-key exception; use updateByPhotoId() to edit an existing record.
     *
     * Only the explicitly defined metadata fields are accepted.
     * Database defaults provide artwork_id, created_at and updated_at.
     */
    public function create(int $photoLibraryId, array $fields = []): int
    {
        $photoLibraryId = $this->positiveId($photoLibraryId);
        $fields = $this->validateFields($fields);
        $columns = ['photo_library_id'];
        $params = [':photo_library_id' => $photoLibraryId];
        foreach ($fields as $name => $value) {
            $columns[] = $name;
            $params[':' . $name] = $value;
        }

        $sql = 'INSERT INTO photos_artwork (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_keys($params)) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Partial update: omitted fields are unchanged; explicit null clears nullable
     * fields. Returns false if no metadata row exists for the photo.
     */
    public function updateByPhotoId(int $photoLibraryId, array $fields): bool
    {
        $photoLibraryId = $this->positiveId($photoLibraryId);
        $fields = $this->validateFields($fields);
        if (!$fields) {
            return $this->findByPhotoId($photoLibraryId) !== null;
        }

        $sets = [];
        $params = [':photo_library_id' => $photoLibraryId];
        foreach ($fields as $name => $value) {
            $sets[] = $name . ' = :' . $name;
            $params[':' . $name] = $value;
        }
        // updated_at has an ON UPDATE default in the database.
        $stmt = $this->pdo->prepare(
            'UPDATE photos_artwork SET ' . implode(', ', $sets)
            . ' WHERE photo_library_id = :photo_library_id'
        );
        $stmt->execute($params);
        // MySQL reports 0 rows on unchanged values; avoid interpreting that as
        // "not found" and check existence instead.
        return $this->findByPhotoId($photoLibraryId) !== null;
    }

    /**
     * Deletes ONLY ARTWORK metadata; does not delete or alter its PHOTOS record.
     * Returns true when a metadata row was removed.
     */
    public function deleteByPhotoId(int $photoLibraryId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM photos_artwork WHERE photo_library_id = :photo_library_id'
        );
        $stmt->execute(['photo_library_id' => $this->positiveId($photoLibraryId)]);
        return $stmt->rowCount() > 0;
    }

    private function positiveId(int $id): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Photo/artwork ID must be positive.');
        }
        return $id;
    }

    private function validateFields(array $fields): array
    {
        $unknown = array_diff(array_keys($fields), self::FIELDS);
        if ($unknown) {
            throw new InvalidArgumentException('Unknown artwork field: ' . (string)reset($unknown));
        }

        // Schema limits based on photos_artwork table.
        $maxLengths = [
            'artist' => 255,
            'display_title' => 255,
            'medium' => 150,
            'museum' => 255,
            'source_url' => 2048,
        ];
        foreach ($fields as $name => &$value) {
            if ($value === null) {
                if ($name === 'circa') {
                    throw new InvalidArgumentException('circa cannot be null.');
                }
                continue;
            }
            if ($name === 'year') {
                if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                    throw new InvalidArgumentException('year must be an integer or null.');
                }
                $value = (int)$value;
                if ($value < 0 || $value > 65535) {
                    throw new InvalidArgumentException('year must be between 0 and 65535.');
                }
            } elseif ($name === 'circa') {
                if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
                    throw new InvalidArgumentException('circa must be 0 or 1.');
                }
                $value = (int)$value;
            } else {
                if (!is_string($value)) {
                    throw new InvalidArgumentException($name . ' must be a string or null.');
                }
                if (isset($maxLengths[$name]) && mb_strlen($value, 'UTF-8') > $maxLengths[$name]) {
                    throw new InvalidArgumentException($name . ' is too long.');
                }
            }
        }
        unset($value);
        return $fields;
    }
}
