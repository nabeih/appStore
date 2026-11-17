<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'name', 'phone', 'policy', 'seo_title', 'seo_description', 'announcement', 'announcement_visible', 'is_open'];

    protected function casts(): array
    {
        return ['is_open' => 'boolean', 'announcement_visible' => 'boolean'];
    }

    public const DEFAULT_ANNOUNCEMENT = ['اختيارات تشبهك، وتفاصيل تحبّها', 'اكتشف التشكيلة'];

    /**
     * عبارات الشريط المتحرك: سطر لكل عبارة، والنص الافتراضي عند الفراغ.
     *
     * @return list<string>
     */
    public function announcementPhrases(): array
    {
        $phrases = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->announcement)), fn (string $phrase): bool => $phrase !== ''));

        return $phrases ?: self::DEFAULT_ANNOUNCEMENT;
    }

    public function showsAnnouncement(): bool
    {
        return $this->announcement_visible !== false;
    }
}
