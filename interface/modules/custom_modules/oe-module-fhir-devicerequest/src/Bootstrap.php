<?php

/**
 * Bootstrap custom module skeleton.  This file is an example custom module that can be used
 * to create modules that can be utilized inside the OpenEMR system.  It is NOT intended for
 * production and is intended to serve as the barebone requirements you need to get started
 * writing modules that can be installed and used in OpenEMR.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\FHIRDeviceRequest;

/**
 * Note the below use statements are importing classes from the OpenEMR core codebase
 */
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Core\TwigEnvironmentEvent;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\Main\Tabs\RenderEvent;
use OpenEMR\Events\RestApiExtend\RestApiResourceServiceEvent;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\FHIR\SMART\SmartLaunchController;
use OpenEMR\Modules\FHIRDeviceRequest\FhirDeviceRequestService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Container;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

// we import our own classes here.. although this use statement is unnecessary it forces the autoloader to be tested.
use OpenEMR\Modules\FHIRDeviceRequest\FHIRDeviceRequestRestController;


class Bootstrap
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_NAME = "oe-module-fhir-devicerequest";
    /**
     * @var EventDispatcherInterface The object responsible for sending and subscribing to events through the OpenEMR system
     */
    private $eventDispatcher;

    /**
     * @var GlobalConfig Holds our module global configuration values that can be used throughout the module.
     */
    private $globalsConfig;

    /**
     * @var string The folder name of the module.  Set dynamically from searching the filesystem.
     */
    private $moduleDirectoryName;

    /**
     * @var \Twig\Environment The twig rendering environment
     */
    private $twig;

    /**
     * @var SystemLogger
     */
    private $logger;

    public function __construct(EventDispatcherInterface $eventDispatcher, ?Kernel $kernel = null)
    {
        global $GLOBALS;

        if (empty($kernel)) {
            $kernel = new Kernel();
        }

        // NOTE: eventually you will be able to pull the twig container directly from the kernel instead of instantiating
        // it here.
        $twig = new TwigContainer($this->getTemplatePath(), $kernel);
        $twigEnv = $twig->getTwig();
        $this->twig = $twigEnv;

        $this->moduleDirectoryName = basename(dirname(__DIR__));
        $this->eventDispatcher = $eventDispatcher;

        // we inject our globals value.
        $this->globalsConfig = new GlobalConfig($GLOBALS);
        $this->logger = new SystemLogger();
    }

    public function subscribeToEvents()
    {
        $this->addGlobalSettings();

        // we only add the rest of our event listeners and configuration if we have been fully setup and configured
        // if ($this->globalsConfig->isConfigured()) {
            $this->registerMenuItems();
            $this->registerTemplateEvents();
            $this->subscribeToApiEvents();
        // }
    }

    /**
     * @return GlobalConfig
     */
    public function getGlobalConfig()
    {
        return $this->globalsConfig;
    }

    public function addGlobalSettings()
    {
        $this->eventDispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, [$this, 'addGlobalSettingsSection']);
    }

    public function addGlobalSettingsSection(GlobalsInitializedEvent $event)
    {
        global $GLOBALS;

        $service = $event->getGlobalsService();
        $section = xlt("FHIR DeviceRequest");
        $service->createSection($section, 'Portal');

        $settings = $this->globalsConfig->getGlobalSettingSectionConfiguration();

        foreach ($settings as $key => $config) {
            $value = $GLOBALS[$key] ?? $config['default'];
            $service->appendToSection(
                $section,
                $key,
                new GlobalSetting(
                    xlt($config['title']),
                    $config['type'],
                    $value,
                    xlt($config['description']),
                    true
                )
            );
        }
    }

    /**
     * We tie into any events dealing with the templates / page rendering of the system here
     */
    public function registerTemplateEvents()
    {
        if ($this->getGlobalConfig()->getGlobalSetting(GlobalConfig::CONFIG_ENABLE_BODY_FOOTER)) {
            $this->eventDispatcher->addListener(RenderEvent::EVENT_BODY_RENDER_POST, [$this, 'renderMainBodyScripts']);
        }
        if ($this->getGlobalConfig()->getGlobalSetting(GlobalConfig::CONFIG_OVERRIDE_TEMPLATES)) {
            $this->eventDispatcher->addListener(TwigEnvironmentEvent::EVENT_CREATED, [$this, 'addTemplateOverrideLoader']);
        }
    }

    /**
     * Add our javascript and css file for the module to the main tabs page of the system
     * @param RenderEvent $event
     */
    public function renderMainBodyScripts(RenderEvent $event)
    {
        ?>
        <link rel="stylesheet" href="<?php echo $this->getAssetPath();?>css/skeleton-module.css">
        <script src="<?php echo $this->getAssetPath();?>js/skeleton-module.js"></script>
        <?php
    }

    /**
     * @param TwigEnvironmentEvent $event
     */
    public function addTemplateOverrideLoader(TwigEnvironmentEvent $event)
    {
        try {
            $twig = $event->getTwigEnvironment();
            if ($twig === $this->twig) {
                // we do nothing if its our own twig environment instantiated that we already setup
                return;
            }
            // we make sure we can override our file system directory here.
            $loader = $twig->getLoader();
            if ($loader instanceof FilesystemLoader) {
                $loader->prependPath($this->getTemplatePath());
            }
        } catch (LoaderError $error) {
            $this->logger->errorLogCaller("Failed to create template loader", ['innerMessage' => $error->getMessage(), 'trace' => $error->getTraceAsString()]);
        }
    }

    public function registerMenuItems()
    {
        if ($this->getGlobalConfig()->getGlobalSetting(GlobalConfig::CONFIG_ENABLE_MENU)) {
            /**
             * @var EventDispatcherInterface $eventDispatcher
             * @var array $module
             * @global                       $eventDispatcher @see ModulesApplication::loadCustomModule
             * @global                       $module @see ModulesApplication::loadCustomModule
             */
            $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, [$this, 'addCustomModuleMenuItem']);
        }
    }

    // /* Adding a service container function to bypass the EHR launch  */

    // private function setupContainer(): Container
    // {
    //     // the container cache will check the service definitions, detect file changes and update the cache if there are
    //     // new definitions as needed.
    //     $containerConfigCache = new ConfigCache(self::CONTAINER_CACHE_FILE, self::DEBUG);
    //     if (!$containerConfigCache->isFresh()) {
    //         $container = new ContainerBuilder();
    //         $this->addSyntheticServicesToContainer($container);
    //         $this->addServicesToContainer($container);
    //         $container->compile();
    //         $dumper = new PhpDumper($container);
    //         $containerConfigCache->write(
    //             $dumper->dump(['class' => 'DacAssessmentCachedContainer', 'namespace' => 'OpenEMR\\Modules\\DiscoverAndChange\\Assessments']),
    //             $container->getResources()
    //         );
    //     }
    //     require_once self::CONTAINER_CACHE_FILE;
    //     $container = new DacAssessmentCachedContainer();
    //     // inject our service values that come from outside our module.
    //     $this->injectSyntheticServicesIntoContainer($container);
    //      return $container;
    // }

    // public function getServiceContainer()
    // {
    //     if (empty($this->serviceContainer)) {
    //         $this->serviceContainer = $this->setupContainer();
    //     }
    //     return $this->serviceContainer;
    // }

    public function addCustomModuleMenuItem(MenuEvent $event)
    {
        $menu = $event->getMenu();

        $menuItem = new \stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = 'mod';
        $menuItem->menu_id = 'mod0';
        $menuItem->label = xlt("FHIR DeviceRequest");
        // TODO: pull the install location into a constant into the codebase so if OpenEMR changes this location it
        // doesn't break any modules.
            /* 
               Adding a comment here because trying to bypass the ehr-launch pages of authentication 
            */
            // $smartAppService = $this->getServiceContainer()->get(SmartAppClientService::class);
            
            
            
            $menuItem->url = "/interface/modules/custom_modules/oe-module-fhir-devicerequest/public/sample-index.php";
            /* 
             If it doesn't work please uncomment the above line
             */

        
        $menuItem->children = [];

        /**
         * This defines the Access Control List properties that are required to use this module.
         * Several examples are provided
         */
        $menuItem->acl_req = [];

        /**
         * If you would like to restrict this menu to only logged in users who have access to see all user data
         */
        //$menuItem->acl_req = ["admin", "users"];

        /**
         * If you would like to restrict this menu to logged in users who can access patient demographic information
         */
        //$menuItem->acl_req = ["users", "demo"];


        /**
         * This menu flag takes a boolean property defined in the $GLOBALS array that OpenEMR populates.
         * It allows a menu item to display if the property is true, and be hidden if the property is false
         */
        //$menuItem->global_req = ["custom_skeleton_module_enable"];

        /**
         * If you want your menu item to allows be shown then leave this property blank.
         */
        $menuItem->global_req = [];

        foreach ($menu as $item) {
            if ($item->menu_id == 'modimg') {
                $item->children[] = $menuItem;
                break;
            }
        }

        $event->setMenu($menu);

        return $event;
    }

    public function subscribeToApiEvents()
    {
        if ($this->getGlobalConfig()->getGlobalSetting(GlobalConfig::CONFIG_ENABLE_FHIR_API)) {
            $this->eventDispatcher->addListener(RestApiCreateEvent::EVENT_HANDLE, [$this, 'addCustomSkeletonApi']);
            $this->eventDispatcher->addListener(RestApiScopeEvent::EVENT_TYPE_GET_SUPPORTED_SCOPES, [$this, 'addApiScope']);
            $this->eventDispatcher->addListener(RestApiResourceServiceEvent::EVENT_HANDLE, [$this, 'addMetadataConformance']);
        }
    }

    public function addCustomSkeletonApi(RestApiCreateEvent $event)
    {
        $apiController = new FHIRDeviceRequestRestController();
    

        /**
         * To see the route definitions @see https://github.com/openemr/openemr/blob/master/_rest_routes.inc.php
         */
        $event->addToFHIRRouteMap('GET /fhir/DeviceRequest', [$apiController, 'listResources']);
        $event->addToFHIRRouteMap('GET /fhir/DeviceRequest/:fhirId', [$apiController, 'getOneResource']);
        $event->addToFHIRRouteMap('POST /fhir/DeviceRequest', [$apiController, 'storeResource']);

        /**
         * Events must ALWAYS be returned
         */
        return $event;
    }

    /**
     * Adds the webhook api scopes to the oauth2 scope validation events for the standard api.  This allows the webhook
     * to be fired.
     * @param RestApiScopeEvent $event
     * @return RestApiScopeEvent
     */
    public function addApiScope(RestApiScopeEvent $event)
    {
        if ($event->getApiType() == RestApiScopeEvent::API_TYPE_FHIR) {
            $scopes = $event->getScopes();
            $scopes[] = 'user/DeviceRequest.read';
            // $scopes[] = 'patient/DeviceRequest.read';
            // only add system scopes if they are actually enabled
            if (\RestConfig::areSystemScopesEnabled())
            {
                $scopes[] = 'system/DeviceRequest.read';
            }
            $event->setScopes($scopes);
        }
        return $event;
    }

    public function addMetadataConformance(RestApiResourceServiceEvent $event)
    {
        $event->setServiceClass(FhirDeviceRequestService::class);
        return $event;
    }

    private function getPublicPath()
    {
        return self::MODULE_INSTALLATION_PATH . ($this->moduleDirectoryName ?? '') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
    }

    private function getAssetPath()
    {
        return $this->getPublicPath() . 'assets' . DIRECTORY_SEPARATOR;
    }

    public function getTemplatePath()
    {
        return \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
    }
}
