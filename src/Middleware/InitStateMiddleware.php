<?php

namespace TractorCow\Fluent\Middleware;

use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\Extension\FluentDirectorExtension;
use TractorCow\Fluent\Model\Domain;
use TractorCow\Fluent\State\FluentState;

/**
 * InitStateMiddleware initialises the FluentState object and sets the current request locale and domain to it
 */
class InitStateMiddleware implements HTTPMiddleware
{
    use Configurable;

    /**
     * URL paths that should be considered as admin only, i.e. not frontend
     *
     * @config
     * @var array
     */
    private static $admin_url_paths = [
        'dev/',
        'graphql/',
    ];

    public function process(HTTPRequest $request, callable $delegate)
    {
        $state = FluentState::create();
        if ($locale = $this->getRequestLocale($request)) {
            $state->setLocale($locale);
        }

        $state
            ->setDomain(Director::host($request))
            ->setIsFrontend($this->getIsFrontend($request))
            ->setIsDomainMode($this->getIsDomainMode($request));

        Injector::inst()->registerService($state, FluentState::class);

        return $delegate($request);
    }

    /**
     * Check for existing locale routing parameters and return if available
     *
     * @param  HTTPRequest $request
     * @return string
     */
    public function getRequestLocale(HTTPRequest $request)
    {
        $queryParam = FluentDirectorExtension::config()->get('query_param');
        return (string) $request->getVar($queryParam);
    }

    /**
     * Determine whether the website is being viewed from the frontend or not
     *
     * @param  HTTPRequest $request
     * @return bool
     */
    public function getIsFrontend(HTTPRequest $request)
    {
        $adminPaths = static::config()->get('admin_url_paths');
        $adminPaths[] = AdminRootController::config()->get('url_base') . '/';
        $currentPath = rtrim($request->getURL(), '/') . '/';

        foreach ($adminPaths as $adminPath) {
            if (substr($currentPath, 0, strlen($adminPath)) === $adminPath) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determine whether the website is running in domain segmentation mode
     *
     * @param  HTTPRequest $request
     * @return boolean
     */
    public function getIsDomainMode(HTTPRequest $request)
    {
        // Don't act in domain mode if none exist
        if (!Domain::getCached()->exists()) {
            return false;
        }

        // Check environment for a forced override
        if (getenv('SS_FLUENT_FORCE_DOMAIN')) {
            return true;
        }

        // Check config for a forced override
        if (FluentDirectorExtension::config()->get('force_domain')) {
            return true;
        }

        // Check if the current domain is included in the list of configured domains (default)
        return Domain::getCached()->filter('Domain', Director::host($request))->exists();
    }
}
