# celerate-update
The Celerate WordPress AutoUpdate provider plugin.

## Upgrading older plugins

The older plugins will use the `woodstreet/wp-auto-updator` package, which is
deprecated now. This dependency must be removed from the plugin. Also, if this
removes the last composer dependency—remove the composer files/autoloader too.

Then follow the below section.

## Correct Usage in Dependent Plugins

First, this must be installed alongside Celerate plugins, from now on.

Second, to provide update features to a plugin, it must define a code block like
below in the plugin:

```php
add_action('plugins_loaded', function () {
    // Halt early if the auto-update plugin isn't installed.
    if (!class_exists(\Celerate\WordPress\AutoUpdateProvider::class)) {
        return;
    }
    
    \Celerate\WordPress\AutoUpdateProvider::register(__FILE__);
});
```
