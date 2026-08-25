<?php

declare(strict_types=1);

namespace App\Livewire\Guides;

use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Sidebar "User Guides" → Student Folder Slides (app.blade.php) — an in-app
 * carousel over the 10 SFDI demo screenshots stored at
 * libraries/sfdi-slides/ on S3. Gated the same as the Event Manager Guide
 * link (VersionRoleAssignmentService::hasActiveOrSandboxVersionRole()); the
 * sidebar only renders the trigger for those users, but this component
 * re-checks independently in case it's ever reached another way, same
 * pattern as UserGuidePdfController.
 */
class StudentFolderSlides extends Component
{
    private const SLIDES_PATH = 'libraries/sfdi-slides';

    /** @var list<array{key: string, title: string}> */
    public array $slides = [];

    public int $current = 0;

    public function mount(): void
    {
        abort_unless(app(VersionRoleAssignmentService::class)->hasActiveOrSandboxVersionRole(Auth::user()), 403);

        $this->slides = $this->loadSlides();
    }

    public function next(): void
    {
        $this->goTo($this->current + 1);
    }

    public function previous(): void
    {
        $this->goTo($this->current - 1);
    }

    public function goTo(int $index): void
    {
        $this->current = max(0, min($index, count($this->slides) - 1));
    }

    public function viewUrl(): string
    {
        return Storage::disk('s3')->temporaryUrl($this->slides[$this->current]['key'], now()->addHour());
    }

    public function downloadUrl(): string
    {
        $title = $this->slides[$this->current]['title'];

        return Storage::disk('s3')->temporaryUrl($this->slides[$this->current]['key'], now()->addHour(), [
            'ResponseContentDisposition' => 'attachment; filename="'.Str::slug($title).'.png"',
        ]);
    }

    public function render()
    {
        return view('livewire.guides.student-folder-slides');
    }

    /**
     * @return list<array{key: string, title: string}>
     */
    private function loadSlides(): array
    {
        return Cache::remember('sfdi-slides-list', now()->addMinutes(15), function () {
            $keys = collect(Storage::disk('s3')->files(self::SLIDES_PATH))
                ->filter(fn (string $key) => in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true))
                ->sort()
                ->values();

            return $keys->map(fn (string $key) => [
                'key' => $key,
                'title' => $this->titleFromKey($key),
            ])->all();
        });
    }

    private function titleFromKey(string $key): string
    {
        $basename = pathinfo($key, PATHINFO_FILENAME);

        $name = preg_match('/^sfdi-\d+-(.+)$/', $basename, $matches) === 1 ? $matches[1] : $basename;

        return Str::title(str_replace(['-', '_'], ' ', $name));
    }
}
