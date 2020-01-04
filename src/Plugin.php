<?php
/**
 * NSM Rollbar plugin for Craft CMS 3.x
 *
 * Rollbar integration for CraftCMS
 *
 * @link      https://newis.com.au
 * @copyright Copyright (c) 2019 Leevi Graham
 */

namespace newism\rollbar;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\ErrorEvent;
use craft\events\ExceptionEvent;
use craft\events\TemplateEvent;
use craft\web\ErrorHandler;
use craft\web\View;
use newism\rollbar\models\Settings;
use Rollbar\Rollbar;
use Rollbar\RollbarJsHelper;
use yii\base\Event;
use yii\queue\Queue;


/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Leevi Graham
 * @package   Rollbar
 * @since     0.0.0
 *
 * @property  array $rollbarConfig
 * @property  array $rollbarJsConfig
 * @property  Settings $settings
 *
 * @method    Settings getSettings()
 */
class Plugin extends BasePlugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Rollbar::$plugin
     *
     * @var Plugin
     */
    public static $plugin;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Rollbar::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->logPluginLoaded();
        $this->registerServerLogger();
        $this->registerJavascriptLogger();
    }

    /**
     * Get Rollbar's configuration.
     *
     * @return array
     */
    public function getRollbarConfig(): array
    {
        return [
            'access_token' => $this->settings->accessToken,
            'environment' => CRAFT_ENVIRONMENT
        ];
    }

    /**
     * Get Rollbar's JS configuration
     *
     * @return array
     */
    public function getRollbarJsConfig(): array
    {
        return [
            'accessToken' => $this->settings->postClientItemAccessToken,
            'captureUncaught' => true,
            'payload' => [
                'environment' => CRAFT_ENVIRONMENT,
            ],
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return Settings
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'newism-rollbar/settings',
            [
                'settings' => $this->getSettings(),
            ]
        );
    }

    /**
     * Register the Server's PHP logger.
     *
     * @return void
     */
    protected function registerServerLogger(): void
    {
        // General exceptions
        if ($this->settings->accessToken) {
            Event::on(
                ErrorHandler::class,
                ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
                function (ExceptionEvent $event) {
                    Rollbar::init($this->getRollbarConfig());
                    Rollbar::error($event->exception);
                }
            );
        }

        // Queue errors.
        Event::on(
            Queue::class,
            Queue::EVENT_AFTER_ERROR,
            function (ErrorEvent $event) {
                Rollbar::init($this->getRollbarConfig());
                Rollbar::error($event->exception);
            }
        );
    }

    /**
     * Register the javascript logger.
     *
     * @return void
     */
    protected function registerJavascriptLogger(): void
    {
        if ($this->settings->enableJs && $this->settings->postClientItemAccessToken) {
            // Load JS before template is rendered
            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_TEMPLATE,
                function (TemplateEvent $event) {
                    $view = Craft::$app->getView();
                    $rollbarJsHelper = new RollbarJsHelper($this->getRollbarJsConfig());
                    $rollbarJs = $rollbarJsHelper->configJsTag() . $rollbarJsHelper->jsSnippet();
                    $view->registerJs($rollbarJs, View::POS_HEAD);
                }
            );
        }
    }

    /**
     * Log plugin loaded message.
     *
     * @return void
     */
    protected function logPluginLoaded(): void
    {
        Craft::info(
            Craft::t(
                'newism-rollbar',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }
}
