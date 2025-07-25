<?php
namespace Celerate\WordPress;

final class AutoUpdateProvider
{
    private string $path;
    private array $pluginData;
    private string $baseUrl;

    protected function __construct(string $pluginFilePath, string $baseUrl)
    {
        $this->path    = $pluginFilePath;
        $this->baseUrl = $baseUrl;

        add_filter('plugins_api', [$this, 'getPluginInfo'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'checkForUpdate']);
    }

    private static array $instances = [];

    /**
     * Example usage, call from the plugins_loaded action:
     * AutoUpdateProvider::register(__FILE__)
     */
    public static function register(string $pluginFilePath): self
    {
        self::maybeCleanupLegacyTransient();

        return self::$instances[$pluginFilePath] ??= new self($pluginFilePath, 'https://gowpupdate.gocelerate.com');
    }

    private function lazyLoadPluginData(): void
    {
        $this->pluginData ??= $this->loadPluginData();
    }

    private function loadPluginData(): array
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data              = get_plugin_data($this->path);
        $data['file_path'] = plugin_basename($this->path);
        $data['slug']      = dirname($data['file_path']);

        return $data;
    }

    private function maybeFetchPluginData()
    {
        if ($cached = get_transient("cau_{$this->pluginData['slug']}")) {
            // Freshen the version info
            $cached->version = $this->pluginData['Version'];

            return $cached;
        }

        $remote = wp_remote_get(
            "{$this->baseUrl}/api/plugin/" . $this->pluginData['slug'],
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($remote)) {
            return $remote;
        }

        if (wp_remote_retrieve_response_code($remote) === 200) {
            $parsed = json_decode(wp_remote_retrieve_body($remote));

            // Match to WP schema
            $result                 = new \stdClass();
            $result->name           = $parsed->name;
            $result->slug           = $parsed->slug;
            $result->author         = $parsed->author;
            $result->author_profile = $parsed->author_profile;
            // The new version comes from the server response.
            $result->new_version = $parsed->version;
            // The current/old version comes from the parsed plugin data.
            $result->version       = $this->pluginData['Version'];
            $result->tested        = $parsed->tested;
            $result->requires      = $parsed->requires;
            $result->requires_php  = $parsed->requires_php;
            $result->download_link = $parsed->download_url;
            $result->trunk         = $parsed->download_url;
            $result->package       = $parsed->download_url;
            $result->last_updated  = $parsed->last_updated;
            $result->sections      = (array) $parsed->sections;
            $result->plugin = $this->pluginData['file_path'];


            if (! empty($parsed->banners)) {
                $result->banners = (array) $parsed->banners;
            }

            // Store the response for 2.5 minutes...
            set_transient("cau_{$this->pluginData['slug']}", $result, 150);

            return $result;
        }

        return false;
    }

    public function getPluginInfo($result, $action, $args)
    {
        $this->lazyLoadPluginData();

        if ('plugin_information' !== $action || strcasecmp($this->pluginData['slug'], $args->slug) !== 0) {
            return $result;
        }

        $remote = $this->maybeFetchPluginData();

        if ($remote === false || is_wp_error($remote)) {
            return $result;
        }

        return $remote;
    }

    public function checkForUpdate($transient)
    {
        $this->lazyLoadPluginData();

        // Normalise
        if (false === $transient || ! is_object($transient)) {
            $transient = new \stdClass();
        }
        $transient->response ??= [];
        $transient->no_update ??= [];

        // Bail early if we already decided
        if (isset($transient->response[$this->pluginData['file_path']])) {
            return $transient;
        }

        // Fetch remote metadata (cached)
        $remote = $this->maybeFetchPluginData();
        if (false === $remote || is_wp_error($remote)) {
            return $transient; // stay silent on network error
        }

        // Version comparison
        $installed = $this->pluginData['Version'];
        $available = $remote->new_version;

        if (version_compare($available, $installed, '>')) {
            $transient->response[$this->pluginData['file_path']] = $remote;
        } else {
            $transient->no_update[$this->pluginData['file_path']] = $remote;
        }

        return $transient;
    }

    private static function maybeCleanupLegacyTransient(): void
    {
        // only run once per site
        if (get_site_option('cau_cleaned_legacy_transient')) {
            return;
        }

        $t = get_site_transient('update_plugins');

        if ($t && isset($t->response) && is_array($t->response)) {
            foreach ($t->response as $obj) {
                if (is_object($obj) && ! isset($obj->plugin)) {
                    delete_site_transient('update_plugins');
                    update_site_option('cau_cleaned_legacy_transient', 1);
                    break;
                }
            }
        }
    }
}
