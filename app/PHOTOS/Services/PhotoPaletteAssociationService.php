<?php
declare(strict_types=1);
namespace App\PHOTOS\Services;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use PDO;
use Throwable;

/** Direct MAIN association only. Presentation photographs remain PALETTES-owned. */
final class PhotoPaletteAssociationService
{
    public function __construct(private PDO $pdo) {}

    public function attach(int $photoId, int $paletteId, bool $replace = false, ?bool $gallery = null): array
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Finish the current transaction before changing the main photo'); }
        $repo=new PdoPhotoLibraryRepository($this->pdo);
        $this->pdo->beginTransaction();
        try {
            // Serialize MAIN changes by the palette, including concurrent requests with different photo IDs.
            $stmt=$this->pdo->prepare('SELECT id FROM saved_palettes WHERE id=?'.($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
            $stmt->execute([$paletteId]);
            if (!$stmt->fetchColumn()) { throw new \InvalidArgumentException('Saved palette not found'); }
            if (!$repo->findById($photoId)) { throw new \InvalidArgumentException('Photo not found'); }
            $others=array_filter($repo->listForPalette($paletteId),static fn(array $p):bool=>(int)$p['photo_library_id']!==$photoId);
            if ($others && !$replace) { throw new \DomainException('Palette already has a Main photo. Explicitly replace it, or save an independent palette for a different room.'); }
            $fields=['palette_id'=>$paletteId];
            if ($gallery!==null) { $fields['show_in_gallery']=$gallery; }
            $repo->update($photoId,$fields);
            foreach($others as$old) { $repo->update((int)$old['photo_library_id'],['palette_id'=>null]); }
            $this->pdo->commit();
        } catch(Throwable $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
        foreach($repo->listForPalette($paletteId) as$photo) { if((int)$photo['photo_library_id']===$photoId) return $photo; }
        throw new \RuntimeException('Could not reload main photograph');
    }
}
