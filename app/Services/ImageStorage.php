<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;

/** Small private images can use external MySQL on hosts without persistent disks. */
final class ImageStorage {
    private static function validate(string $name): void {
        if (!preg_match('/^[a-f0-9]{40}\.png$/', $name)) throw new \RuntimeException('Invalid image name.');
    }
    private static function database(): bool { return getenv('SCHOOLLEDGER_IMAGE_STORAGE') === 'database'; }
    public static function put(int $schoolId, string $name, string $bytes): void {
        self::validate($name);
        if (strlen($bytes) > 4 * 1024 * 1024) throw new \DomainException('Processed image exceeds 4 MB. Choose a smaller image.');
        if (self::database()) {
            DB::insert('stored_images', ['school_id'=>$schoolId,'name'=>$name,'contents'=>$bytes]);
        } elseif (file_put_contents(ROOT.'/uploads/'.$name, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to save image.');
        }
    }
    public static function get(int $schoolId, string $name): ?string {
        if (!preg_match('/^[a-f0-9]{40}\.png$/', $name)) return null;
        if (self::database()) return DB::one('SELECT contents FROM stored_images WHERE school_id=? AND name=?', [$schoolId,$name])['contents'] ?? null;
        $path=ROOT.'/uploads/'.$name;
        return is_file($path) ? file_get_contents($path) : null;
    }
    public static function remove(int $schoolId, string $name): void {
        self::validate($name);
        if (self::database()) DB::run('DELETE FROM stored_images WHERE school_id=? AND name=?', [$schoolId,$name]);
        elseif (is_file(ROOT.'/uploads/'.$name)) unlink(ROOT.'/uploads/'.$name);
    }
}
