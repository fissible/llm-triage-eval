<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Allow large CloudHub log uploads on the Ingest Logs page. Livewire's
        // temporary-upload validator defaults to 12 MB; raise it to 300 MB.
        // (PHP's own upload_max_filesize/post_max_size must also be raised — see README.)
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:307200']]);
    }
}
