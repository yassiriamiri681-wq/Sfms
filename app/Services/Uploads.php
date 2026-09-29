<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Auth,DB};
final class Uploads {
    public static function save(string $kind,int $id): void {
        if($kind==='student') { Auth::require('students.write'); Auth::owned('students',$id); } else { Auth::require('settings.manage'); $id=Auth::schoolId(); }
        $file=$_FILES['image']??null;
        if(!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>2*1024*1024 || !is_uploaded_file($file['tmp_name'])) throw new \DomainException('Choose a JPEG or PNG image under 2 MB.');
        $info=getimagesize($file['tmp_name']); if(!$info || !in_array($info['mime'],['image/jpeg','image/png'],true) || $info[0]>4000 || $info[1]>4000) throw new \DomainException('Image must be JPEG/PNG and at most 4000 × 4000 pixels.');
        $image=$info['mime']==='image/png'?imagecreatefrompng($file['tmp_name']):imagecreatefromjpeg($file['tmp_name']); if(!$image) throw new \DomainException('Could not decode image.');
        $name=bin2hex(random_bytes(20)).'.png';
        ob_start(); imagepng($image); $bytes=ob_get_clean(); imagedestroy($image);
        try { DB::transaction(function () use($kind,$id,$name,$bytes) { ImageStorage::put(Auth::schoolId(),$name,$bytes); if($kind==='student') DB::run('UPDATE students SET photo_path=? WHERE school_id=? AND id=?',[$name,Auth::schoolId(),$id]); else DB::run('UPDATE schools SET logo_path=? WHERE id=?',[$name,$id]); Auth::audit('image.uploaded',$kind,$id); }); } catch(\Throwable $e) { if(getenv('SCHOOLLEDGER_IMAGE_STORAGE')!=='database') ImageStorage::remove(Auth::schoolId(),$name); throw $e; }
    }
}
