<?php

namespace App\View\Components\Admin;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Layout extends Component
{
    public function __construct(
        public string $heading = 'Dashboard',
        public string $subheading = 'Review bookings, payments, and calendar availability.',
        public ?string $application = null,
        public ?string $breadcrumb = null,
        public string $title = 'Socal Admin',
    ) {}

    public function render(): View|Closure|string
    {
        return view('admin.layout');
    }
}
