<?php

namespace App\Domain\Identity;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\SecurityMutation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UserAvatarService
{
    private const DISK = 'user_avatars';

    public function __construct(
        private readonly BusinessAuthorization $authorization,
        private readonly SecurityMutation $mutations,
    ) {}

    public static function libraryIds(): array
    {
        return [
            ...array_map(static fn (int $id): string => sprintf('portrait-%03d', $id), range(1, 40)),
            ...array_map(static fn (int $id): string => sprintf('abstract-%03d', $id), range(1, 24)),
        ];
    }

    public function selectSelf(User $actor, ?string $libraryId): void
    {
        $this->change($actor, $actor, null, $this->selection($libraryId));
    }

    public function selectForAdministrator(User $actor, Location $context, string $targetId, ?string $libraryId): void
    {
        $this->authorizeAdministrator($actor, $context);
        $target = User::where('organization_id', $actor->organization_id)->whereKey($targetId)->firstOrFail();
        $this->change($actor, $target, $context, $this->selection($libraryId));
    }

    public function uploadSelf(User $actor, UploadedFile $photo): void
    {
        $this->upload($actor, $actor, null, $photo);
    }

    public function uploadForAdministrator(User $actor, Location $context, string $targetId, UploadedFile $photo): void
    {
        $this->authorizeAdministrator($actor, $context);
        $target = User::where('organization_id', $actor->organization_id)->whereKey($targetId)->firstOrFail();
        $this->upload($actor, $target, $context, $photo);
    }

    public function selfPhoto(User $user): StreamedResponse
    {
        return $this->streamPhoto($user);
    }

    public function administratorPhoto(User $actor, Location $context, string $targetId): StreamedResponse
    {
        $this->authorizeAdministrator($actor, $context);
        $target = User::where('organization_id', $actor->organization_id)->whereKey($targetId)->firstOrFail();
        if (! $this->authorization->canManageAccount($actor, $target)) {
            throw new AuthorizationException;
        }

        return $this->streamPhoto($target);
    }

    private function upload(User $actor, User $target, ?Location $context, UploadedFile $photo): void
    {
        $this->authorizeMutation($actor, $target, $context);
        $contents = file_get_contents($photo->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages(['photo' => 'Nie można odczytać przesłanego zdjęcia.']);
        }
        $normalized = $this->normalizeImage($contents);
        $photoKey = (string) Str::uuid();
        $path = $this->photoPath($target, $photoKey);
        try {
            if (! Storage::disk(self::DISK)->put($path, $normalized)) {
                throw new \RuntimeException('Could not persist the normalized avatar image.');
            }
            $this->change($actor, $target, $context, [
                'avatar_type' => UserAvatarType::Upload,
                'avatar_library_id' => null,
                'avatar_photo_key' => $photoKey,
            ]);
        } catch (\Throwable $exception) {
            $this->deleteFile($path, 'A newly uploaded avatar could not be removed after a failed update.');
            throw $exception;
        }
    }

    private function selection(?string $libraryId): array
    {
        if ($libraryId === null) {
            return [
                'avatar_type' => UserAvatarType::Initials,
                'avatar_library_id' => null,
                'avatar_photo_key' => null,
            ];
        }
        if (! in_array($libraryId, self::libraryIds(), true)) {
            throw ValidationException::withMessages(['library_id' => 'Wybrany awatar nie istnieje.']);
        }

        return [
            'avatar_type' => UserAvatarType::Library,
            'avatar_library_id' => $libraryId,
            'avatar_photo_key' => null,
        ];
    }

    private function change(User $actor, User $target, ?Location $context, array $avatar): void
    {
        $oldPhotoKey = null;
        $this->mutations->run($actor, $target, $context, 'account.profile_update', function () use (
            $actor, $target, $context, $avatar, &$oldPhotoKey,
        ): User {
            $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = User::where('organization_id', $lockedActor->organization_id)
                ->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->authorizeMutation($lockedActor, $lockedTarget, $context);
            $oldPhotoKey = $lockedTarget->avatar_photo_key;
            $lockedTarget->forceFill($avatar)->save();

            return $lockedTarget;
        });

        if ($oldPhotoKey !== null && $oldPhotoKey !== ($avatar['avatar_photo_key'] ?? null)) {
            $this->deleteFile($this->photoPath($target, (string) $oldPhotoKey), 'An obsolete user avatar could not be removed.');
        }
    }

    private function authorizeMutation(User $actor, User $target, ?Location $context): void
    {
        if ($actor->organization_id !== $target->organization_id) {
            throw new AuthorizationException;
        }
        if ($context === null) {
            if ($actor->is($target) && $actor->is_active && $actor->email_verified_at !== null
                && $actor->organization->is_active) {
                return;
            }
            throw new AuthorizationException;
        }
        $this->authorizeAdministrator($actor, $context);
        if (! $this->authorization->canManageAccount($actor, $target)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeAdministrator(User $actor, Location $context): void
    {
        if (! $this->authorization->eligible($actor, $context)
            || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            throw new AuthorizationException;
        }
    }

    private function normalizeImage(string $contents): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $image = @getimagesizefromstring($contents);
        if (! is_array($image) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ($image['mime'] ?? null) !== $mime
            || $image[0] < 1 || $image[0] !== $image[1] || $image[0] > 4096
            || $image[0] * $image[1] > 12_000_000) {
            throw ValidationException::withMessages(['photo' => 'Plik nie jest obsługiwanym, poprawnym obrazem.']);
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new \LogicException('The GD extension with WebP support is required for avatar processing.');
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw ValidationException::withMessages(['photo' => 'Nie można bezpiecznie odczytać przesłanego obrazu.']);
        }
        $output = imagecreatetruecolor(256, 256);
        if ($output === false) {
            imagedestroy($source);
            throw new \RuntimeException('Could not allocate the normalized avatar image.');
        }

        try {
            imagealphablending($output, false);
            imagesavealpha($output, true);
            $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
            imagefill($output, 0, 0, $transparent);
            imagecopyresampled(
                $output,
                $source,
                0,
                0,
                0,
                0,
                256,
                256,
                $image[0],
                $image[1],
            );
            ob_start();
            $written = imagewebp($output, null, 86);
            $normalized = ob_get_clean();
            if (! $written || ! is_string($normalized) || $normalized === '') {
                throw new \RuntimeException('Could not encode the normalized avatar image.');
            }

            return $normalized;
        } finally {
            imagedestroy($output);
            imagedestroy($source);
        }
    }

    private function streamPhoto(User $user): StreamedResponse
    {
        if ($user->avatar_type !== UserAvatarType::Upload || $user->avatar_photo_key === null) {
            abort(404);
        }
        $path = $this->photoPath($user, (string) $user->avatar_photo_key);
        $stream = Storage::disk(self::DISK)->readStream($path);
        if (! is_resource($stream)) {
            Log::error('A referenced user avatar file is missing.');
            abort(404);
        }

        return response()->stream(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Cookie',
        ]);
    }

    private function photoPath(User $user, string $key): string
    {
        return "users/{$user->id}/{$key}.webp";
    }

    private function deleteFile(string $path, string $message): void
    {
        try {
            $disk = Storage::disk(self::DISK);
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new \RuntimeException('The avatar file was not deleted.');
            }
        } catch (\Throwable $exception) {
            Log::error($message, ['exception_class' => $exception::class]);
        }
    }
}
