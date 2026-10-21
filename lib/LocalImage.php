<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Filesystem\Asset;

final class LocalImage extends File
{
    private Asset $source;

    public function __construct(string $relative, string $alt)
    {
        $this->source = new Asset('assets/social-media/instagram/' . $relative);
        parent::__construct([
            'filename' => $this->source->filename(),
            'parent' => Page::factory(['slug' => 'social-media-preview', 'template' => 'default']),
            'url' => $this->source->url(),
            'content' => ['alt' => $alt],
        ]);
        $this->root = $this->source->root();
    }

    public function mediaDir(): string { return $this->source->mediaDir(); }
    public function mediaHash(): string { return $this->source->mediaHash(); }
    public function mediaRoot(?string $filename = null): string { return $this->source->mediaRoot($filename); }
    public function mediaUrl(?string $filename = null): string { return $this->source->mediaUrl($filename); }
}
