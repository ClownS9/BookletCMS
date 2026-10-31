<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Asset;
use App\Booklet\Data\AssetContainer;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Models\ActivityLog;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Media: the files in an asset container. Files live on the container's
 * Laravel disk; their alt text, size and dimensions live in Booklet's asset
 * meta (database or files, depending on the "assets" driver).
 *
 * Worked out when asked (#[Computed]):
 *
 * @property-read AssetContainer|null $containerModel
 * @property-read mixed $assets
 * @property-read Asset|null $chosen
 * @property-read array $folders
 */
#[Layout('components.layouts.admin')]
#[Title('Media')]
class Media extends Component
{
    use WithFileUploads, Stamps;

    use \App\Livewire\Concerns\Paged;

    protected function pagedList(): string
    {
        return 'media';
    }

    protected function pageSizes(): array
    {
        return [24, 48, 96];
    }

    protected function defaultPageSize(): int
    {
        return 48;
    }

    protected function pageResetsOn(): array
    {
        return ['search', 'folder', 'container', 'noAlt', 'sort'];
    }

    #[Url(as: 'in', except: '')]
    public string $container = '';

    /** "" means every folder. */
    #[Url(except: '')]
    public string $folder = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'file', except: null)]
    public ?string $selected = null;

    /** @var TemporaryUploadedFile[] */
    public array $uploads = [];

    public string $alt = '';

    public string $newFolder = '';

    // Picture sizes (admins) ----------------------------------------------------------------

    public bool $sizesOpen = false;

    /** @var list<array{key: string, handle: string, label: string, width: int|string, height: int|string}> */
    public array $sizeRows = [];

    public function openSizes(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->sizeRows = collect(\App\Support\Crops::sizes())
            ->map(fn ($s, $h) => ['key' => Str::random(6), 'handle' => $h, 'label' => $s['label'], 'width' => $s['width'], 'height' => $s['height']])
            ->values()->all();
        $this->sizesOpen = true;
    }

    public function closeSizes(): void
    {
        $this->sizesOpen = false;
        $this->resetErrorBag();
    }

    public function addSize(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        if (count($this->sizeRows) < \App\Support\Crops::MAX_SIZES) {
            $this->sizeRows[] = ['key' => Str::random(6), 'handle' => '', 'label' => '', 'width' => 1200, 'height' => 800];
        }
    }

    public function removeSize(int $i): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        unset($this->sizeRows[$i]);
        $this->sizeRows = array_values($this->sizeRows);
    }

    public function saveSizes(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $max = \App\Support\Crops::MAX_SIDE;
        $this->validate([
            'sizeRows' => ['array', 'max:'.\App\Support\Crops::MAX_SIZES],
            'sizeRows.*.label' => ['required', 'string', 'max:40', 'regex:/[A-Za-z]/', 'distinct:ignore_case'],
            'sizeRows.*.width' => ['required', 'integer', 'min:16', 'max:'.$max],
            'sizeRows.*.height' => ['required', 'integer', 'min:16', 'max:'.$max],
        ], [
            'sizeRows.*.label.regex' => 'Give it a name with letters in it.',
            'sizeRows.*.label.distinct' => 'Two sizes can’t share a name.',
        ], ['sizeRows.*.label' => 'name', 'sizeRows.*.width' => 'width', 'sizeRows.*.height' => 'height']);

        $sizes = [];
        foreach ($this->sizeRows as $row) {
            // A size keeps its handle (themes and cut-outs ask for it by that) unless it's new.
            $handle = $row['handle'] ?: Str::snake(Str::slug((string) $row['label'], ' '));
            $sizes[$handle] = ['label' => $row['label'], 'width' => (int) $row['width'], 'height' => (int) $row['height']];
        }
        \App\Support\Crops::save($sizes);
        \App\Models\ActivityLog::record('changed the picture sizes', 'settings', 'settings', 'picture_sizes', 'Picture sizes');
        $this->openSizes();
        $this->dispatch('stamp', text: 'Sizes saved. Pictures are cut in them as they’re first needed.');
    }

    public function resetSizes(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        \App\Support\Crops::save([]);
        $this->openSizes();
        $this->dispatch('stamp', text: 'Back to Booklet’s own sizes.');
    }

    /** Roles whose card leaves Media alone can look and choose, not change. */
    protected function mayChange(): void
    {
        abort_unless(request()->user()->canChangeMedia(), 403, 'Your role can’t change Media.');
    }

    // Grid or list ------------------------------------------------------------

    /** The album as photos (grid) or as a table (list); each person's own choice. */
    public string $view = 'grid';

    /** The list's order: newest (''), name, -size, -date, date. */
    #[Url(except: '')]
    public string $sort = '';

    /** Only files without alt text (pictures readers can't see described). */
    #[Url(as: 'noalt', except: false)]
    public bool $noAlt = false;

    public function setView(string $view): void
    {
        $this->view = $view === 'list' ? 'list' : 'grid';
        if ($user = request()->user()) {
            $user->forceFill(['preferences' => array_merge((array) $user->preferences, ['media_view' => $this->view])])->save();
        }
    }

    public function sortBy(string $column): void
    {
        abort_unless(in_array($column, ['name', 'size', 'date'], true), 422);
        $first = $column === 'name' ? 'name' : '-'.$column;
        $turned = $first === $column ? '-'.$column : $column;
        $this->sort = $this->sort === $first ? $turned : $first;
        $this->page = 1;
    }

    public function mount(): void
    {
        $this->view = (((array) request()->user()?->preferences)['media_view'] ?? 'grid') === 'list' ? 'list' : 'grid';
        $this->container = $this->container ?: (string) Booklet::assetContainers()->all()->first()?->handle();
        $this->loadAlt();
    }

    #[Computed]
    public function containerModel(): ?AssetContainer
    {
        return $this->container ? Booklet::assetContainers()->find($this->container) : null;
    }

    protected function disk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        return \App\Support\Disk::of($this->containerModel?->attribute('disk') ?? 'public');
    }

    #[Computed]
    public function assets()
    {
        return $this->container ? Booklet::assets()->inContainer($this->container) : collect();
    }

    #[Computed]
    public function chosen(): ?Asset
    {
        return $this->selected
            ? $this->assets->first(fn (Asset $a) => $a->path() === $this->selected)
            : null;
    }

    public function pick(string $path): void
    {
        $this->selected = $path;
        unset($this->chosen);
        $this->loadAlt();
    }

    protected function loadAlt(): void
    {
        $this->alt = (string) ($this->chosen?->get('alt') ?? '');
    }

    // Uploads --------------------------------------------------------------

    public function updatedUploads(): void
    {
        $this->mayChange();
        $this->validate([
            'uploads' => ['array', 'max:20'],
            'uploads.*' => ['file', 'max:102400', 'mimes:'.AssetUploader::MIMES],
        ], [
            'uploads.*.max' => 'Each file can be up to 100 MB.',
            'uploads.*.mimes' => 'That kind of file can’t go in Media.',
        ]);

        abort_unless($this->containerModel !== null, 404);

        $uploader = new AssetUploader($this->container);
        $last = null;

        foreach ($this->uploads as $upload) {
            $last = $uploader->store($upload, $this->folder);
        }

        $count = count($this->uploads);
        $this->uploads = [];
        unset($this->assets);

        if ($last) {
            $this->pick($last->path());
            ActivityLog::record("uploaded {$count} ".Str::plural('file', $count).' to', 'uploads', 'asset', $this->container, $this->containerModel->title());
            $this->stamp($count === 1 ? 'Taped in.' : __(':count files taped in.', ['count' => $count]));
        }
    }

    protected function recordAsset(string $path, array $keep = []): Asset
    {
        return (new AssetUploader($this->container))->record($path, $keep);
    }

    // Folders & sync -------------------------------------------------------

    #[Computed]
    public function folders(): array
    {
        $configured = (array) ($this->containerModel?->get('folders') ?? []);
        $used = $this->assets->map(fn (Asset $a) => $a->attribute('folder'))->reject(fn ($f) => $f === '/')->all();

        $folders = array_values(array_unique(array_merge($configured, $used)));
        sort($folders);

        return $folders;
    }

    public function createFolder(): void
    {
        $this->mayChange();
        $this->validate(['newFolder' => ['required', 'string', 'max:60']], [], ['newFolder' => 'folder name']);

        $slug = Str::slug($this->newFolder);
        $container = $this->containerModel;
        $folders = array_values(array_unique(array_merge((array) $container->get('folders', []), [$slug])));

        $container->set('folders', $folders)->save();
        $this->disk()->makeDirectory($slug);

        $this->newFolder = '';
        $this->folder = $slug;
        unset($this->containerModel, $this->folders);
    }

    /**
     * Statamic-style: find files put on the disk some other way (FTP, a deploy)
     * and give them meta, and forget meta for files that are gone.
     */
    public function sync(): void
    {
        $this->mayChange();
        abort_unless($this->containerModel !== null, 404);

        // Booklet's own folders (sized copies, profile pictures, the logo…) aren't part of the album.
        $onDisk = collect($this->disk()->allFiles())
            ->reject(fn ($p) => str_starts_with(basename($p), '.') || \App\Support\Pictures::isSystem($p));
        $known = $this->assets->keyBy(fn (Asset $a) => $a->path());

        $added = 0;
        foreach ($onDisk as $path) {
            if (! $known->has($path)) {
                $this->recordAsset($path);
                $added++;
            }
        }

        $removed = 0;
        foreach ($known as $path => $asset) {
            if (! $onDisk->contains($path)) {
                Booklet::assets()->delete($asset);
                $removed++;
            }
        }

        unset($this->assets);
        $this->stamp($added || $removed
            ? trans_choice('Found :count new file, removed :removed missing.|Found :count new files, removed :removed missing.', $added, ['removed' => $removed])
            : __('Everything is already in the album.'), afterRedirect: true);
        $this->redirectRoute('admin.media', ['in' => $this->container], navigate: true);
    }

    // The chosen file ------------------------------------------------------

    public function saveAlt(): void
    {
        $this->mayChange();
        $asset = $this->chosen;
        if (! $asset) {
            return;
        }

        $this->validate(['alt' => ['nullable', 'string', 'max:500']]);
        $asset->set('alt', $this->alt === '' ? null : $this->alt);
        Booklet::assets()->save($asset);

        unset($this->assets, $this->chosen);
        $this->stamp('Alt text saved.');
    }

    /** The spot that matters in the picture: kept in view wherever it's cropped. */
    public function setFocus(float $x, float $y): void
    {
        $this->mayChange();
        $asset = $this->chosen;
        if (! $asset || ! $asset->isImage()) {
            return;
        }

        $asset->set('focus', \App\Support\Focus::store($x, $y));
        Booklet::assets()->save($asset);

        unset($this->assets, $this->chosen);
        $this->stamp('Focal point saved.');
    }

    public function clearFocus(): void
    {
        $this->mayChange();
        $asset = $this->chosen;
        if (! $asset) {
            return;
        }

        $asset->remove('focus');
        Booklet::assets()->save($asset);

        unset($this->assets, $this->chosen);
        $this->stamp('Back to the middle.');
    }

    public function deleteChosen(): void
    {
        $this->mayChange();
        $asset = $this->chosen;
        if (! $asset) {
            return;
        }

        // Into the bin for 30 days (the file too), with Undo.
        $binned = \App\Support\Bin::asset($asset);

        $this->selected = null;
        $this->alt = '';
        unset($this->assets, $this->chosen);
        $this->stamp('Moved to the bin.', undo: $binned->id);
    }

    protected function assetUrl(Asset $asset): ?string
    {
        try {
            return $this->disk()->url($asset->path());
        } catch (\Throwable) {
            return null;
        }
    }

    public function render()
    {
        $visible = $this->assets
            ->when($this->folder !== '', fn ($c) => $c->filter(fn (Asset $a) => $a->attribute('folder') === $this->folder))
            ->when($this->search !== '', fn ($c) => $c->filter(
                fn (Asset $a) => Str::contains(Str::lower($a->path().' '.$a->get('alt')), Str::lower($this->search))
            ))
            ->when($this->noAlt, fn ($c) => $c->filter(fn (Asset $a) => $a->isImage() && trim((string) $a->get('alt', '')) === ''))
            ->values();
        if (! in_array($this->sort, ['', 'name', '-name', 'size', '-size', 'date', '-date'], true)) {
            $this->sort = '';
        }
        if ($this->sort !== '') {
            $by = ltrim($this->sort, '-');
            $visible = $visible->sortBy(fn (Asset $a) => match ($by) {
                'name' => Str::lower((string) $a->attribute('basename')),
                'size' => (int) $a->get('size', 0),
                default => (string) ($a->lastModified() ?? ''),
            }, SORT_NATURAL, str_starts_with($this->sort, '-'))->values();
        }
        $visible = $this->pageOf($visible);

        $folderCounts = $this->assets->countBy(fn (Asset $a) => $a->attribute('folder'));

        return view('livewire.admin.media', [
            'canChange' => request()->user()->canChangeMedia(),
            'pictureSizes' => \App\Support\Crops::sizes(),
            'containers' => Booklet::assetContainers()->all(),
            'visible' => $visible,
            'folderCounts' => $folderCounts,
            'total' => $this->assets->count(),
            'missingAlt' => $this->assets->filter(fn (Asset $a) => $a->isImage() && trim((string) $a->get('alt', '')) === '')->count(),
            'urls' => $visible->getCollection()->concat(array_filter([$this->chosen]))
                ->mapWithKeys(fn (Asset $a) => [$a->path() => $this->assetUrl($a)])
                ->all(),
        ]);
    }
}
