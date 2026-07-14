<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandingSetting extends Model
{
    protected $fillable = [
        'app_name',
        'tagline',
        'logo_path',
        'logo_dark_path',
        'admin_logo_inverse',
        'admin_logo_size_percent',
        'favicon_path',
        'primary_color',
        'secondary_color',
        'support_email',
    ];

    protected $casts = [
        'admin_logo_inverse' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'app_name' => config('app.name', 'Email Server'),
            'primary_color' => '#0d7a3a',
            'secondary_color' => '#c9a227',
        ]);
    }

    public function logoUrl(): ?string
    {
        return $this->assetUrl($this->logo_path);
    }

    public function logoDarkUrl(): ?string
    {
        return $this->assetUrl($this->logo_dark_path ?? $this->logo_path);
    }

    public function faviconUrl(): ?string
    {
        return $this->assetUrl($this->favicon_path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'app_name' => $this->app_name,
            'tagline' => $this->tagline,
            'logo_url' => $this->logoUrl(),
            'logo_dark_url' => $this->logoDarkUrl(),
            'admin_logo_inverse' => (bool) $this->admin_logo_inverse,
            'admin_logo_size_percent' => (int) ($this->admin_logo_size_percent ?: 100),
            'favicon_url' => $this->faviconUrl(),
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'support_email' => $this->support_email,
        ];
    }

    private function assetUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Same-origin relative URL so host Nginx can proxy /storage/ → API
        // (absolute APP_URL alone does not help if /storage hits the SPA).
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        return '/storage/'.$normalized;
    }
}
