<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';
use App\PHOTOS\Services\PhotoThumbnailService;
use App\PHOTOS\Services\PhotoDeliveryService;
use App\PHOTOS\Endpoints\PhotoThumbnailEndpoint;

$root=sys_get_temp_dir().'/colorfix-optimization-'.bin2hex(random_bytes(5));
mkdir($root.'/photos',0755,true);
$root=realpath($root);
$pdo=new PDO('sqlite:'.$root.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY,rel_path TEXT,updated_at TEXT,show_in_gallery INTEGER DEFAULT 1,client_id INTEGER,is_inactive INTEGER DEFAULT 0,is_retired INTEGER DEFAULT 0,photo_permission_status TEXT)');
$pdo->exec('CREATE TABLE photo_crops (photo_crop_id INTEGER PRIMARY KEY,photo_library_id INTEGER,crop_type TEXT,crop_x REAL,crop_y REAL,crop_width REAL,crop_height REAL,created_at TEXT,updated_at TEXT)');
$checks=0;
$expect=function(bool $value,string $message) use (&$checks) { if (!$value) throw new RuntimeException($message); $checks++; };
$keep=in_array('--fixture',$argv,true);
ob_start();
try {
    // A deterministic detailed PNG: neutrals, blue and gold with fine texture.
    $im=imagecreatetruecolor(1200,1800);
    for($y=0;$y<1800;$y++) for($x=0;$x<1200;$x++) {
        $n=(($x*13+$y*17)%29)-14;
        $base=$y<600 ? [150,150,150] : ($y<1200 ? [30,85,175] : [190,145,45]);
        imagesetpixel($im,$x,$y,(max(0,min(255,$base[0]+$n))<<16)|(max(0,min(255,$base[1]+$n))<<8)|max(0,min(255,$base[2]+$n)));
    }
    imagepng($im,$root.'/photos/original.png',0); imagedestroy($im);
    $hash=hash_file('sha256',$root.'/photos/original.png');
    $insert=$pdo->prepare("INSERT INTO photo_library (photo_library_id,rel_path,updated_at) VALUES (?,?,'2026-11-12')");
    for($i=1;$i<=160;$i++) $insert->execute([$i,'/photos/original.png']);
    $pdo->exec("INSERT INTO photo_crops VALUES (1,1,'swatch',0,0,1,0.533333,NULL,NULL)");
    $service=new PhotoThumbnailService($pdo);
    $crop=$service->get(1,520,88,$root);
    $expect(array_slice(getimagesize($crop['path']),0,2)===[520,416],'Exact gallery crop dimensions');
    $display=$service->get(1,1920,88,$root,'original','inside');
    $expect(array_slice(getimagesize($display['path']),0,2)===[1200,1800],'Art composition preserved without upscaling');
    $preview=$service->get(1,960,88,$root,'original','inside');
    $expect(array_slice(getimagesize($preview['path']),0,2)===[640,960],'Portrait display fits longest edge');
    $expect(filesize($crop['path'])<filesize($root.'/photos/original.png')/10,'Real encoded thumbnail is less than one tenth of original bytes');
    $expect($service->get(1,520,88,$root)['path']===$crop['path'],'Stable derivative cache reuse');
    $pdo->exec('UPDATE photo_crops SET crop_y=0.466667 WHERE photo_crop_id=1');
    $changed=$service->get(1,520,88,$root);
    $expect($changed['path']!==$crop['path'],'Crop change invalidates URL-independent disk identity');
    $pdo->exec('UPDATE photo_crops SET crop_y=0 WHERE photo_crop_id=1');
    // Alpha must survive PNG -> WebP (or lossless PNG fallback).
    $im=imagecreatetruecolor(320,200);imagealphablending($im,false);imagesavealpha($im,true);
    imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));
    $colors=[[160,160,160],[32,84,170],[191,145,45]];
    foreach($colors as $i=>$rgb) imagefilledrectangle($im,$i*100+10,20,$i*100+90,180,imagecolorallocatealpha($im,...[...$rgb,0]));
    imagepng($im,$root.'/photos/alpha.png');imagedestroy($im);
    $insert->execute([201,'/photos/alpha.png']);
    $alpha=$service->get(201,320,88,$root,'original');
    $decoded=imagecreatefromstring(file_get_contents($alpha['path']));
    $expect(imagecolorsforindex($decoded,imagecolorat($decoded,0,0))['alpha']>=120,'Transparency is preserved');
    foreach($colors as $i=>$rgb) {
        $pixel=imagecolorsforindex($decoded,imagecolorat($decoded,$i*100+50,100));
        foreach(['red','green','blue'] as $channel=>$name) $expect(abs($pixel[$name]-$rgb[$channel])<=8,'Neutral/blue/gold color channel within 8 levels');
    }
    imagedestroy($decoded);
    // Landscape full display is bounded by longest edge, preserving composition.
    $im=imagecreatetruecolor(2400,1200); imagefill($im,0,0,0x456789);
    imagepng($im,$root.'/photos/landscape.png'); imagedestroy($im);
    $insert->execute([204,'/photos/landscape.png']);
    $landscape=$service->get(204,1920,88,$root,'original','inside');
    $expect(array_slice(getimagesize($landscape['path']),0,2)===[1920,960],'Landscape longest edge capped without distortion');
    // Subtle neutral gradients must remain neutral and close to the source values.
    $im=imagecreatetruecolor(320,200);
    for($x=0;$x<320;$x++) { $n=130+(int)round($x/8); imageline($im,$x,0,$x,199,($n<<16)|($n<<8)|$n); }
    imagepng($im,$root.'/photos/gradient.png');imagedestroy($im);
    $insert->execute([205,'/photos/gradient.png']);
    $gradient=$service->get(205,320,88,$root,'original');
    $decoded=imagecreatefromstring(file_get_contents($gradient['path']));
    for($x=20;$x<320;$x+=40) {
        $rgb=imagecolorsforindex($decoded,imagecolorat($decoded,$x,100));$expected=130+(int)round($x/8);
        $expect(max(abs($rgb['red']-$expected),abs($rgb['green']-$expected),abs($rgb['blue']-$expected))<=5,'Subtle neutral gradient stays within five channel levels');
    }
    imagedestroy($decoded);
    // EXIF orientation 6, raw image 300x200 -> displayed image 200x300.
    $im=imagecreatetruecolor(300,200);imagefilledrectangle($im,0,0,299,199,0x336699);
    ob_start();imagejpeg($im,null,95);$jpeg=ob_get_clean();imagedestroy($im);
    $exif="Exif\0\0II\x2a\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0\x06\0\0\0\0\0\0\0";
    file_put_contents($root.'/photos/oriented.jpg',substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
    $insert->execute([202,'/photos/oriented.jpg']);
    $oriented=$service->get(202,320,88,$root,'original');
    $expect(array_slice(getimagesize($oriented['path']),0,2)===[200,300],'EXIF orientation is preserved in derivative');
    // An embedded profile cannot be silently stripped by GD.
    $icc="ICC_PROFILE\0\x01\x01fixture-profile";
    file_put_contents($root.'/photos/profile.jpg',substr($jpeg,0,2)."\xff\xe2".pack('n',strlen($icc)+2).$icc.substr($jpeg,2));
    $insert->execute([203,'/photos/profile.jpg']);
    if (!class_exists(Imagick::class)) {
        $tagged=$service->get(203,160,88,$root,'original');
        $expect(!$tagged['optimized'] && $tagged['path']===$root.'/photos/profile.jpg','Unavailable color management retains original profile/bytes');
    }
    $expect(hash_file('sha256',$root.'/photos/original.png')===$hash,'Original PNG never changes');
    // No public ID-only access to private/client images, even if a cached file exists.
    $private=['photo_library_id'=>1,'rel_path'=>'/photos/original.png','show_in_gallery'=>1,'client_id'=>7];
    $expect(!PhotoDeliveryService::authorized($private,[],false),'Client photo requires authorization');
    $expect(PhotoDeliveryService::authorized($private,[],true),'Verified admin may access client photo');
    putenv('PHOTOS_DELIVERY_SIGNING_KEY='.str_repeat('fixture-key-',4));
    $url=PhotoDeliveryService::url(1,'/photos/original.png');parse_str(parse_url($url,PHP_URL_QUERY),$query);
    $expect(PhotoDeliveryService::authorized($private,$query,false),'Authorized response capability grants private delivery');
    $expect(!PhotoDeliveryService::authorized([...$private,'photo_library_id'=>2],$query,false),'Capability cannot authorize another photo');
    $expect(!PhotoDeliveryService::authorized([...$private,'rel_path'=>'/photos/replacement.png'],$query,false),'Replacement invalidates previous capability');
    $expect(!PhotoDeliveryService::authorized($private,$query,false,(int)$query['expires']+1),'Expired capability rejected');
    $expect(!PhotoDeliveryService::authorized($private,[...$query,'signature'=>'bad'],false),'Forged signature rejected');
    $pdo->exec('UPDATE photo_library SET client_id=7 WHERE photo_library_id=1');
    $_GET=['id'=>1];ob_start();PhotoThumbnailEndpoint::handle($pdo,$root);ob_end_clean();
    $expect(http_response_code()===404,'Cached private photo denied without grant');
    $_GET=$query;http_response_code(200);ob_start();PhotoThumbnailEndpoint::handle($pdo,$root);$bytes=ob_get_clean();
    $expect(http_response_code()===200 && getimagesizefromstring($bytes)!==false,'Signed endpoint returns real image');
    $etag='"'.hash('sha256',$bytes).'"';$_SERVER['HTTP_IF_NONE_MATCH']=$etag;
    ob_start();PhotoThumbnailEndpoint::handle($pdo,$root);$body=ob_get_clean();
    $expect(http_response_code()===304 && $body==='','Warm conditional request returns no body');
    unset($_SERVER['HTTP_IF_NONE_MATCH']);http_response_code(200);
    $pdo->exec('UPDATE photo_library SET client_id=NULL WHERE photo_library_id=1');
    PhotoThumbnailService::invalidate(201,$root);
    $expect(!is_file($alpha['path']),'Derivative invalidation removes generated files');
    $expect(is_file($root.'/photos/alpha.png'),'Derivative invalidation never removes original');
    ob_end_clean();
    echo $keep ? json_encode(['root'=>$root,'original_bytes'=>filesize($root.'/photos/original.png'),'thumbnail_bytes'=>filesize($crop['path']),'checks'=>$checks])."\n"
        : "PASS: {$checks} size/bytes/crop/color/alpha/orientation/access/cache/original-preservation checks\n";
} finally {
    if (!$keep) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $f) $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);
    }
}
