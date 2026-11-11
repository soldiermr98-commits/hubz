<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use Throwable;

/** SQL expressions shared by the queue and the explicit human-review snapshot. */
final class BusinessDataReviewService
{
    public static function schemaReady(): bool
    {
        try { return DB::fetchOne("SHOW TABLES LIKE 'business_data_reviews'") !== null; }
        catch (Throwable $e) { return false; }
    }

    // These are the same first photo and phone that the public catalog renders.
    // Uses 1-level correlated subqueries so MariaDB 10.x (DirectAdmin) and MySQL 8 both execute cleanly.
    public static function effectivePhotoId(): string
    {
        return "COALESCE(b.cover_media_id,(SELECT qbp.media_id FROM business_photos qbp WHERE qbp.business_id=b.id AND qbp.status='approved' ORDER BY qbp.sort ASC,qbp.id ASC LIMIT 1))";
    }
    public static function imagePath(): string
    {
        return "CASE WHEN b.cover_media_id IS NOT NULL "
            . "THEN COALESCE((SELECT qmc.file_path_480 FROM media qmc WHERE qmc.id=b.cover_media_id LIMIT 1),'') "
            . "ELSE COALESCE((SELECT qmp.file_path_480 FROM business_photos qbp INNER JOIN media qmp ON qmp.id=qbp.media_id WHERE qbp.business_id=b.id AND qbp.status='approved' ORDER BY qbp.sort ASC,qbp.id ASC LIMIT 1),'') "
            . "END";
    }
    public static function phone(): string
    {
        return "COALESCE((SELECT qc.value FROM business_contacts qc WHERE qc.business_id=b.id AND qc.type IN ('phone','mobile') ORDER BY qc.is_primary DESC,qc.id ASC LIMIT 1),'')";
    }
    public static function missing(): array
    {
        $photo = self::imagePath(); $phone = self::phone();
        return [
            'photo'=>"TRIM({$photo})=''",
            'description'=>"TRIM(COALESCE(b.short_desc,''))=''",
            'phone'=>"TRIM({$phone})=''",
            'address'=>"TRIM(COALESCE(b.address,''))=''",
            'category'=>"NOT EXISTS (SELECT 1 FROM categories qcat WHERE qcat.id=b.category_id)",
        ];
    }
    public static function hashExpression(): string
    {
        $phone = self::phone(); $photo = self::imagePath();
        // Fixed-length per-field hashes avoid ambiguous separators in source text.
        return "SHA2(CONCAT(SHA2(COALESCE(b.name,''),256),SHA2(COALESCE(CAST(b.city_id AS CHAR),''),256),"
            . "SHA2(COALESCE(CAST(b.category_id AS CHAR),''),256),SHA2(COALESCE(b.address,''),256),"
            . "SHA2(COALESCE(b.short_desc,''),256),SHA2({$phone},256),SHA2({$photo},256),"
            . "SHA2(COALESCE(CAST(b.updated_at AS CHAR),''),256)),256)";
    }
}
