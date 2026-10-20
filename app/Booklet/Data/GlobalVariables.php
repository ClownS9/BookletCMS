<?php

namespace App\Booklet\Data;

/**
 * The values of a global set for one site, e.g. site_name and tagline.
 */
class GlobalVariables extends Item
{
    public static function repositoryName(): string
    {
        return 'global_set_variables';
    }

    public function handle(): string
    {
        return $this->attribute('handle');
    }

    public function locale(): string
    {
        return $this->attribute('locale');
    }
}
