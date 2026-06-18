<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

interface AIStoreProviderInterface {
    public function createStore(string $name = 'WebsiteSearch'): bool;
    public function deleteStore(): void;
    public function getStoreData(): string;
}
