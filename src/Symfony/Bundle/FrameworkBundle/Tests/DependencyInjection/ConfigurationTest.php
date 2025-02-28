<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\DependencyInjection;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\Configuration;
use Symfony\Bundle\FullStack;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Notifier;
use Symfony\Component\RateLimiter\Policy\TokenBucketLimiter;
use Symfony\Component\Uid\Factory\UuidFactory;

class ConfigurationTest extends TestCase
{
    public function testDefaultConfig()
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(true), [['secret' => 's3cr3t']]);

        $this->assertEquals(self::getBundleDefaultConfig(), $config);
    }

    public function getTestValidSessionName()
    {
        return [
            [null],
            ['PHPSESSID'],
            ['a&b'],
            [',_-!@#$%^*(){}:<>/?'],
        ];
    }

    /**
     * @dataProvider getTestInvalidSessionName
     */
    public function testInvalidSessionName($sessionName)
    {
        $this->expectException(InvalidConfigurationException::class);
        $processor = new Processor();
        $processor->processConfiguration(
            new Configuration(true),
            [['session' => ['name' => $sessionName]]]
        );
    }

    public function getTestInvalidSessionName()
    {
        return [
            ['a.b'],
            ['a['],
            ['a[]'],
            ['a[b]'],
            ['a=b'],
            ['a+b'],
        ];
    }

    public function testAssetsCanBeEnabled()
    {
        $processor = new Processor();
        $configuration = new Configuration(true);
        $config = $processor->processConfiguration($configuration, [['assets' => null]]);

        $defaultConfig = [
            'enabled' => true,
            'version_strategy' => null,
            'version' => null,
            'version_format' => '%%s?%%s',
            'base_path' => '',
            'base_urls' => [],
            'packages' => [],
            'json_manifest_path' => null,
            'strict_mode' => false,
        ];

        $this->assertEquals($defaultConfig, $config['assets']);
    }

    /**
     * @dataProvider provideValidAssetsPackageNameConfigurationTests
     */
    public function testValidAssetsPackageNameConfiguration($packageName)
    {
        $processor = new Processor();
        $configuration = new Configuration(true);
        $config = $processor->processConfiguration($configuration, [
            [
                'assets' => [
                    'packages' => [
                        $packageName => [],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey($packageName, $config['assets']['packages']);
    }

    public function provideValidAssetsPackageNameConfigurationTests()
    {
        return [
            ['foobar'],
            ['foo-bar'],
            ['foo_bar'],
        ];
    }

    /**
     * @dataProvider provideInvalidAssetConfigurationTests
     */
    public function testInvalidAssetsConfiguration(array $assetConfig, $expectedMessage)
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $processor = new Processor();
        $configuration = new Configuration(true);
        $processor->processConfiguration($configuration, [
                [
                    'assets' => $assetConfig,
                ],
            ]);
    }

    public function provideInvalidAssetConfigurationTests()
    {
        // helper to turn config into embedded package config
        $createPackageConfig = function (array $packageConfig) {
            return [
                'base_urls' => '//example.com',
                'version' => 1,
                'packages' => [
                    'foo' => $packageConfig,
                ],
            ];
        };

        $config = [
            'version' => 1,
            'version_strategy' => 'foo',
        ];
        yield [$config, 'You cannot use both "version_strategy" and "version" at the same time under "assets".'];
        yield [$createPackageConfig($config), 'You cannot use both "version_strategy" and "version" at the same time under "assets" packages.'];

        $config = [
            'json_manifest_path' => '/foo.json',
            'version_strategy' => 'foo',
        ];
        yield [$config, 'You cannot use both "version_strategy" and "json_manifest_path" at the same time under "assets".'];
        yield [$createPackageConfig($config), 'You cannot use both "version_strategy" and "json_manifest_path" at the same time under "assets" packages.'];

        $config = [
            'json_manifest_path' => '/foo.json',
            'version' => '1',
        ];
        yield [$config, 'You cannot use both "version" and "json_manifest_path" at the same time under "assets".'];
        yield [$createPackageConfig($config), 'You cannot use both "version" and "json_manifest_path" at the same time under "assets" packages.'];
    }

    /**
     * @dataProvider provideValidLockConfigurationTests
     */
    public function testValidLockConfiguration($lockConfig, $processedConfig)
    {
        $processor = new Processor();
        $configuration = new Configuration(true);
        $config = $processor->processConfiguration($configuration, [
            [
                'lock' => $lockConfig,
            ],
        ]);

        $this->assertArrayHasKey('lock', $config);

        $this->assertEquals($processedConfig, $config['lock']);
    }

    public function provideValidLockConfigurationTests()
    {
        yield [null, ['enabled' => true, 'resources' => ['default' => [class_exists(SemaphoreStore::class) && SemaphoreStore::isSupported() ? 'semaphore' : 'flock']]]];

        yield ['flock', ['enabled' => true, 'resources' => ['default' => ['flock']]]];
        yield [['flock', 'semaphore'], ['enabled' => true, 'resources' => ['default' => ['flock', 'semaphore']]]];
        yield [['foo' => 'flock', 'bar' => 'semaphore'], ['enabled' => true, 'resources' => ['foo' => ['flock'], 'bar' => ['semaphore']]]];
        yield [['foo' => ['flock', 'semaphore'], 'bar' => 'semaphore'], ['enabled' => true, 'resources' => ['foo' => ['flock', 'semaphore'], 'bar' => ['semaphore']]]];
        yield [['default' => 'flock'], ['enabled' => true, 'resources' => ['default' => ['flock']]]];

        yield [['enabled' => false, 'flock'], ['enabled' => false, 'resources' => ['default' => ['flock']]]];
        yield [['enabled' => false, ['flock', 'semaphore']], ['enabled' => false, 'resources' => ['default' => ['flock', 'semaphore']]]];
        yield [['enabled' => false, 'foo' => 'flock', 'bar' => 'semaphore'], ['enabled' => false, 'resources' => ['foo' => ['flock'], 'bar' => ['semaphore']]]];
        yield [['enabled' => false, 'foo' => ['flock', 'semaphore']], ['enabled' => false, 'resources' => ['foo' => ['flock', 'semaphore']]]];
        yield [['enabled' => false, 'default' => 'flock'], ['enabled' => false, 'resources' => ['default' => ['flock']]]];

        yield [['resources' => 'flock'], ['enabled' => true, 'resources' => ['default' => ['flock']]]];
        yield [['resources' => ['flock', 'semaphore']], ['enabled' => true, 'resources' => ['default' => ['flock', 'semaphore']]]];
        yield [['resources' => ['foo' => 'flock', 'bar' => 'semaphore']], ['enabled' => true, 'resources' => ['foo' => ['flock'], 'bar' => ['semaphore']]]];
        yield [['resources' => ['foo' => ['flock', 'semaphore'], 'bar' => 'semaphore']], ['enabled' => true, 'resources' => ['foo' => ['flock', 'semaphore'], 'bar' => ['semaphore']]]];
        yield [['resources' => ['default' => 'flock']], ['enabled' => true, 'resources' => ['default' => ['flock']]]];

        yield [['enabled' => false, 'resources' => 'flock'], ['enabled' => false, 'resources' => ['default' => ['flock']]]];
        yield [['enabled' => false, 'resources' => ['flock', 'semaphore']], ['enabled' => false, 'resources' => ['default' => ['flock', 'semaphore']]]];
        yield [['enabled' => false, 'resources' => ['foo' => 'flock', 'bar' => 'semaphore']], ['enabled' => false, 'resources' => ['foo' => ['flock'], 'bar' => ['semaphore']]]];
        yield [['enabled' => false, 'resources' => ['foo' => ['flock', 'semaphore'], 'bar' => 'semaphore']], ['enabled' => false, 'resources' => ['foo' => ['flock', 'semaphore'], 'bar' => ['semaphore']]]];
        yield [['enabled' => false, 'resources' => ['default' => 'flock']], ['enabled' => false, 'resources' => ['default' => ['flock']]]];

        // xml

        yield [['resource' => ['flock']], ['enabled' => true, 'resources' => ['default' => ['flock']]]];
        yield [['resource' => ['flock', ['name' => 'foo', 'value' => 'semaphore']]], ['enabled' => true, 'resources' => ['default' => ['flock'], 'foo' => ['semaphore']]]];
        yield [['resource' => [['name' => 'foo', 'value' => 'flock']]], ['enabled' => true, 'resources' => ['foo' => ['flock']]]];
        yield [['resource' => [['name' => 'foo', 'value' => 'flock'], ['name' => 'foo', 'value' => 'semaphore']]], ['enabled' => true, 'resources' => ['foo' => ['flock', 'semaphore']]]];
        yield [['resource' => [['name' => 'foo', 'value' => 'flock'], ['name' => 'bar', 'value' => 'semaphore']]], ['enabled' => true, 'resources' => ['foo' => ['flock'], 'bar' => ['semaphore']]]];
        yield [['resource' => [['name' => 'foo', 'value' => 'flock'], ['name' => 'foo', 'value' => 'semaphore'], ['name' => 'bar', 'value' => 'semaphore']]], ['enabled' => true, 'resources' => ['foo' => ['flock', 'semaphore'], 'bar' => ['semaphore']]]];

        yield [['enabled' => false, 'resource' => ['flock']], ['enabled' => false, 'resources' => ['default' => ['flock']]]];
        yield [['enabled' => false, 'resource' => ['flock', ['name' => 'foo', 'value' => 'semaphore']]], ['enabled' => false, 'resources' => ['default' => ['flock'], 'foo' => ['semaphore']]]];
        yield [['enabled' => false, 'resource' => [['name' => 'foo', 'value' => 'flock']]], ['enabled' => false, 'resources' => ['foo' => ['flock']]]];
        yield [['enabled' => false, 'resource' => [['name' => 'foo', 'value' => 'flock'], ['name' => 'foo', 'value' => 'semaphore']]], ['enabled' => false, 'resources' => ['foo' => ['flock', 'semaphore']]]];
        yield [['enabled' => false, 'resource' => [['name' => 'foo', 'value' => 'flock'], ['name' => 'bar', 'value' => 'semaphore']]], ['enabled' => false, 'resources' => ['foo' => ['flock'], 'bar' => ['semaphore']]]];
        yield [['enabled' => false, 'resource' => [['name' => 'foo', 'value' => 'flock'], ['name' => 'foo', 'value' => 'semaphore'], ['name' => 'bar', 'value' => 'semaphore']]], ['enabled' => false, 'resources' => ['foo' => ['flock', 'semaphore'], 'bar' => ['semaphore']]]];
    }

    public function testLockMergeConfigs()
    {
        $processor = new Processor();
        $configuration = new Configuration(true);
        $config = $processor->processConfiguration($configuration, [
            [
                'lock' => [
                    'payload' => 'flock',
                ],
            ],
            [
                'lock' => [
                    'payload' => 'semaphore',
                ],
            ],
        ]);

        $this->assertEquals(
            [
                'enabled' => true,
                'resources' => [
                    'payload' => ['semaphore'],
                ],
            ],
            $config['lock']
        );
    }

    public function testItShowANiceMessageIfTwoMessengerBusesAreConfiguredButNoDefaultBus()
    {
        $expectedMessage = 'You must specify the "default_bus" if you define more than one bus.';
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);
        $processor = new Processor();
        $configuration = new Configuration(true);

        $processor->processConfiguration($configuration, [
            'framework' => [
                'messenger' => [
                    'default_bus' => null,
                    'buses' => [
                        'first_bus' => [],
                        'second_bus' => [],
                    ],
                ],
            ],
        ]);
    }

    public function testBusMiddlewareDontMerge()
    {
        $processor = new Processor();
        $configuration = new Configuration(true);
        $config = $processor->processConfiguration($configuration, [
            [
                'messenger' => [
                    'default_bus' => 'existing_bus',
                    'buses' => [
                        'existing_bus' => [
                            'middleware' => 'existing_bus.middleware',
                        ],
                        'common_bus' => [
                            'default_middleware' => false,
                            'middleware' => 'common_bus.old_middleware',
                        ],
                    ],
                ],
            ],
            [
                'messenger' => [
                    'buses' => [
                        'common_bus' => [
                            'middleware' => 'common_bus.new_middleware',
                        ],
                        'new_bus' => [
                            'middleware' => 'new_bus.middleware',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertEquals(
            [
                'existing_bus' => [
                    'default_middleware' => true,
                    'middleware' => [
                        ['id' => 'existing_bus.middleware', 'arguments' => []],
                    ],
                ],
                'common_bus' => [
                    'default_middleware' => false,
                    'middleware' => [
                        ['id' => 'common_bus.new_middleware', 'arguments' => []],
                    ],
                ],
                'new_bus' => [
                    'default_middleware' => true,
                    'middleware' => [
                        ['id' => 'new_bus.middleware', 'arguments' => []],
                    ],
                ],
            ],
            $config['messenger']['buses']
        );
    }

    public function testItErrorsWhenDefaultBusDoesNotExist()
    {
        $processor = new Processor();
        $configuration = new Configuration(true);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The specified default bus "foo" is not configured. Available buses are "bar", "baz".');

        $processor->processConfiguration($configuration, [
            [
                'messenger' => [
                    'default_bus' => 'foo',
                    'buses' => [
                        'bar' => null,
                        'baz' => null,
                    ],
                ],
            ],
        ]);
    }

    protected static function getBundleDefaultConfig()
    {
        return [
            'http_method_override' => true,
            'ide' => null,
            'default_locale' => 'en',
            'enabled_locales' => [],
            'set_locale_from_accept_language' => false,
            'set_content_language_from_locale' => false,
            'secret' => 's3cr3t',
            'trusted_hosts' => [],
            'trusted_headers' => [
                'x-forwarded-for',
                'x-forwarded-port',
                'x-forwarded-proto',
            ],
            'csrf_protection' => [
                'enabled' => false,
            ],
            'form' => [
                'enabled' => !class_exists(FullStack::class),
                'csrf_protection' => [
                    'enabled' => null, // defaults to csrf_protection.enabled
                    'field_name' => '_token',
                ],
                'legacy_error_messages' => true,
            ],
            'esi' => ['enabled' => false],
            'ssi' => ['enabled' => false],
            'fragments' => [
                'enabled' => false,
                'path' => '/_fragment',
                'hinclude_default_template' => null,
            ],
            'profiler' => [
                'enabled' => false,
                'only_exceptions' => false,
                'only_master_requests' => false,
                'only_main_requests' => false,
                'dsn' => 'file:%kernel.cache_dir%/profiler',
                'collect' => true,
                'collect_parameter' => null,
            ],
            'translator' => [
                'enabled' => !class_exists(FullStack::class),
                'fallbacks' => [],
                'cache_dir' => '%kernel.cache_dir%/translations',
                'logging' => false,
                'formatter' => 'translator.formatter.default',
                'paths' => [],
                'default_path' => '%kernel.project_dir%/translations',
                'enabled_locales' => [],
                'pseudo_localization' => [
                    'enabled' => false,
                    'accents' => true,
                    'expansion_factor' => 1.0,
                    'brackets' => true,
                    'parse_html' => false,
                    'localizable_html_attributes' => [],
                ],
                'providers' => [],
            ],
            'validation' => [
                'enabled' => !class_exists(FullStack::class),
                'enable_annotations' => !class_exists(FullStack::class),
                'static_method' => ['loadValidatorMetadata'],
                'translation_domain' => 'validators',
                'mapping' => [
                    'paths' => [],
                ],
                'auto_mapping' => [],
                'not_compromised_password' => [
                    'enabled' => true,
                    'endpoint' => null,
                ],
            ],
            'annotations' => [
                'cache' => 'php_array',
                'file_cache_dir' => '%kernel.cache_dir%/annotations',
                'debug' => true,
                'enabled' => true,
            ],
            'serializer' => [
                'default_context' => [],
                'enabled' => !class_exists(FullStack::class),
                'enable_annotations' => !class_exists(FullStack::class),
                'mapping' => ['paths' => []],
            ],
            'property_access' => [
                'enabled' => true,
                'magic_call' => false,
                'magic_get' => true,
                'magic_set' => true,
                'throw_exception_on_invalid_index' => false,
                'throw_exception_on_invalid_property_path' => true,
            ],
            'property_info' => [
                'enabled' => !class_exists(FullStack::class),
            ],
            'router' => [
                'enabled' => false,
                'default_uri' => null,
                'http_port' => 80,
                'https_port' => 443,
                'strict_requirements' => true,
                'utf8' => null,
            ],
            'session' => [
                'enabled' => false,
                'storage_id' => 'session.storage.native',
                'storage_factory_id' => null,
                'handler_id' => 'session.handler.native_file',
                'cookie_httponly' => true,
                'cookie_samesite' => null,
                'gc_probability' => 1,
                'save_path' => '%kernel.cache_dir%/sessions',
                'metadata_update_threshold' => 0,
            ],
            'request' => [
                'enabled' => false,
                'formats' => [],
            ],
            'assets' => [
                'enabled' => !class_exists(FullStack::class),
                'version_strategy' => null,
                'version' => null,
                'version_format' => '%%s?%%s',
                'base_path' => '',
                'base_urls' => [],
                'packages' => [],
                'json_manifest_path' => null,
                'strict_mode' => false,
            ],
            'cache' => [
                'pools' => [],
                'app' => 'cache.adapter.filesystem',
                'system' => 'cache.adapter.system',
                'directory' => '%kernel.cache_dir%/pools/app',
                'default_redis_provider' => 'redis://localhost',
                'default_memcached_provider' => 'memcached://localhost',
                'default_doctrine_dbal_provider' => 'database_connection',
                'default_pdo_provider' => ContainerBuilder::willBeAvailable('doctrine/dbal', Connection::class, ['symfony/framework-bundle']) ? 'database_connection' : null,
                'prefix_seed' => '_%kernel.project_dir%.%kernel.container_class%',
            ],
            'workflows' => [
                'enabled' => false,
                'workflows' => [],
            ],
            'php_errors' => [
                'log' => true,
                'throw' => true,
            ],
            'web_link' => [
                'enabled' => !class_exists(FullStack::class),
            ],
            'lock' => [
                'enabled' => !class_exists(FullStack::class),
                'resources' => [
                    'default' => [
                        class_exists(SemaphoreStore::class) && SemaphoreStore::isSupported() ? 'semaphore' : 'flock',
                    ],
                ],
            ],
            'messenger' => [
                'enabled' => !class_exists(FullStack::class) && interface_exists(MessageBusInterface::class),
                'routing' => [],
                'transports' => [],
                'failure_transport' => null,
                'serializer' => [
                    'default_serializer' => 'messenger.transport.native_php_serializer',
                    'symfony_serializer' => [
                        'format' => 'json',
                        'context' => [],
                    ],
                ],
                'default_bus' => null,
                'buses' => ['messenger.bus.default' => ['default_middleware' => true, 'middleware' => []]],
                'reset_on_message' => null,
            ],
            'disallow_search_engine_index' => true,
            'http_client' => [
                'enabled' => !class_exists(FullStack::class) && class_exists(HttpClient::class),
                'scoped_clients' => [],
            ],
            'mailer' => [
                'dsn' => null,
                'transports' => [],
                'enabled' => !class_exists(FullStack::class) && class_exists(Mailer::class),
                'message_bus' => null,
                'headers' => [],
            ],
            'notifier' => [
                'enabled' => !class_exists(FullStack::class) && class_exists(Notifier::class),
                'chatter_transports' => [],
                'texter_transports' => [],
                'channel_policy' => [],
                'admin_recipients' => [],
                'notification_on_failed_messages' => false,
            ],
            'error_controller' => 'error_controller',
            'secrets' => [
                'enabled' => true,
                'vault_directory' => '%kernel.project_dir%/config/secrets/%kernel.runtime_environment%',
                'local_dotenv_file' => '%kernel.project_dir%/.env.%kernel.environment%.local',
                'decryption_env_var' => 'base64:default::SYMFONY_DECRYPTION_SECRET',
            ],
            'http_cache' => [
                'enabled' => false,
                'debug' => '%kernel.debug%',
                'private_headers' => [],
            ],
            'rate_limiter' => [
                'enabled' => !class_exists(FullStack::class) && class_exists(TokenBucketLimiter::class),
                'limiters' => [],
            ],
            'uid' => [
                'enabled' => !class_exists(FullStack::class) && class_exists(UuidFactory::class),
                'default_uuid_version' => 6,
                'name_based_uuid_version' => 5,
                'time_based_uuid_version' => 6,
            ],
            'exceptions' => [],
        ];
    }
}
