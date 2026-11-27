<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Controller;
use App\Models\InstallationSetting;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Application branding: one name and one logo for the whole installation
 * (docs/decisions.md, "Platform Owner, Organization Owner, dan Member": branding is
 * installation-level and only the Platform Owner changes it).
 *
 * Reading is public — the login page shows it before anyone is signed in. Writing
 * is behind `platform.owner` and recorded in the platform audit log. An
 * Organization Owner's own entity name and entity logo are workspace data and keep
 * their existing workspace endpoints.
 */
class BrandingController extends Controller
{
    public const DEFAULT_NAME = 'Akunta';

    public function __construct(private readonly PlatformAuditLogger $platformAudit) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'min:1', 'max:60'],
            // `image` excludes SVG by default: an uploaded SVG could carry script.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        $userId = $request->user()?->getAuthIdentifier();
        $name = trim($data['app_name']);
        InstallationSetting::write(InstallationSetting::APP_NAME, $name === self::DEFAULT_NAME ? null : $name, $userId);

        $logoChanged = false;
        $previous = InstallationSetting::read(InstallationSetting::LOGO_PATH);
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('branding', 'public');
            InstallationSetting::write(InstallationSetting::LOGO_PATH, $path, $userId);
            $logoChanged = true;
        } elseif ($request->boolean('remove_logo') && $previous !== null) {
            InstallationSetting::write(InstallationSetting::LOGO_PATH, null, $userId);
            $logoChanged = true;
        }
        if ($logoChanged && $previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        $this->platformAudit->record('platform.branding_updated', 'branding', null, [
            'app_name' => $name,
            'logo_changed' => $logoChanged,
        ]);

        return response()->json(['data' => $this->payload()]);
    }

    /** @return array{app_name: string, logo_url: string|null} */
    private function payload(): array
    {
        $logo = InstallationSetting::read(InstallationSetting::LOGO_PATH);

        return [
            'app_name' => InstallationSetting::read(InstallationSetting::APP_NAME) ?? self::DEFAULT_NAME,
            'logo_url' => $logo === null ? null : Storage::disk('public')->url($logo),
        ];
    }
}
