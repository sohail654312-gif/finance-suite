<?php

declare(strict_types=1);

use App\Models\Menu;
use App\Services\MenuService;

it('renders nested navigation in order and escapes labels and allowed links', function (): void {
    Menu::create(['name' => 'Last link', 'url' => '/help', 'order' => 2]);
    $parent = Menu::create(['name' => 'Finance & Reports', 'url' => '/reports', 'order' => 1]);
    Menu::create(['parent_id' => $parent->getKey(), 'name' => '<Financial guide>', 'url' => 'https://example.test/guide?a=1&b=2', 'order' => 1]);
    Menu::create(['parent_id' => $parent->getKey(), 'name' => 'Unsupported link', 'url' => 'custom-scheme:example', 'order' => 2]);

    $html = app(MenuService::class)->buildMenu()->render();
    expect($html)->toContain('Finance &amp; Reports', '&lt;Financial guide&gt;', 'https://example.test/guide?a=1&amp;b=2', 'href="#"')
        ->not->toContain('<Financial guide>', 'custom-scheme:example');
    expect(strpos($html, 'Finance &amp; Reports'))->toBeLessThan(strpos($html, 'Last link'));
});
