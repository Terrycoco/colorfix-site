<?php
declare(strict_types=1);
namespace App\PHOTOS\Endpoints;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use PDO;

/** Compatibility URLs for the current direct Photo Library palette association. */
final class PhotoPaletteEndpoint
{
    public static function handle(PDO $pdo, string $action): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $method = $action === 'links' ? 'GET' : 'POST';
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) { self::send(['ok'=>false,'error'=>$method.' only'],405); return; }
            $data = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($data)) { throw new \InvalidArgumentException('Invalid JSON'); }
            $id = (int)($data['photo_library_id'] ?? 0);
            if ($id <= 0) { throw new \InvalidArgumentException('photo_library_id required; retired photo-row IDs are not accepted'); }
            $repo = new PdoPhotoLibraryRepository($pdo);
            $photo = $repo->findById($id);
            if (!$photo) { self::send(['ok'=>false,'error'=>'Photo not found'],404); return; }
            if ($action === 'links') { self::send(['ok'=>true,'items'=>$repo->listPaletteLinks($id)]); return; }
            if ($action === 'attach') {
                if (!empty($data['create_new_set']) || !empty($data['set_id'])) { throw new \InvalidArgumentException('Palette Sets are retired. Use the direct palette association.'); }
                $paletteId = (int)($data['palette_id'] ?? 0);
                if ($paletteId <= 0) { throw new \InvalidArgumentException('palette_id required'); }
                if (($data['photo_type'] ?? 'full') !== 'full') {
                    throw new \InvalidArgumentException('Before and Zoom photos belong to the current Palette Viewer editor');
                }
                $photo=(new \App\PHOTOS\Services\PhotoPaletteAssociationService($pdo))->attach(
                    $id,$paletteId,!empty($data['replace_existing_full']),
                    array_key_exists('show_in_gallery',$data)?!empty($data['show_in_gallery']):null
                );
                self::send(['ok'=>true,'photo'=>$photo]);
                return;
            }
            if ($action === 'detach') {
                $repo->update($id,['palette_id'=>null]);
                self::send(['ok'=>true,'photo_cleanup'=>[['photo_library_id'=>$id,'retained'=>true,'reason'=>'Palette association removed; Photo Library asset retained']]]);
                return;
            }
            throw new \InvalidArgumentException('Unknown association action');
        } catch (\DomainException $e) { self::send(['ok'=>false,'error'=>$e->getMessage()],409);
        } catch (\InvalidArgumentException $e) { self::send(['ok'=>false,'error'=>$e->getMessage()],400);
        } catch (\RuntimeException $e) {
            error_log('PHOTOS palette association: '.$e->getMessage());
            self::send(['ok'=>false,'error'=>$e->getCode()===409?$e->getMessage():'Photo association failed'],$e->getCode()===409?409:500);
        } catch (\Throwable $e) { error_log('PHOTOS palette association: '.$e->getMessage()); self::send(['ok'=>false,'error'=>'Photo association failed'],500); }
    }
    private static function send(array $data, int $status=200): void
    {
        http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES);
    }
}
