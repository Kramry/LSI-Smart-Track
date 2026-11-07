<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

interface AnalyticsProviderInterface
{
    public function report($startDate, $endDate);

    public function cacheNamespace();
}
