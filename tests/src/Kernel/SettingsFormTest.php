<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Engine;
use Drupal\strata\Form\SettingsFormBase;
use Drupal\strata\Form\StorageSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionProperty;

/**
 * Proves a settings form is constructible from both containers a real site produces.
 *
 * **A form's `create()` runs before the form exists, so nothing inside the form can guard it.**
 * Drupal resolves `_form` through `ClassResolver::getInstanceFromDefinition()`, which calls the
 * static factory and hands whatever it throws straight to the exception subscriber; there is no
 * `guard()` to reach and no page to degrade into. Whatever `create()` asks the container for is
 * therefore a hard requirement of the route answering at all.
 *
 * That matters here more than it does in most modules, because `composer.json` PSR-4 maps
 * `Drupal\strata\` onto `src/`. Every class under it loads off composer's autoloader whether or not
 * the module is in `core.extension`, while `strata.services.yml` reaches the container only when it
 * is. A site whose `router` table still holds these routes after the module left resolves the class
 * and finds no services, and 1.0.2 answered that with a 500:
 *
 * `ServiceNotFoundException: You have requested a non-existent service "strata.engine"`
 *
 * The two directions are asserted separately because losing either is a real defect. Dropping the
 * ask breaks the reset after a save, which is the only reason the engine is held at all; keeping it
 * unguarded brings the 500 back.
 *
 * @see SettingsFormBase::create()
 * @see SettingsFormBase::submitForm()
 */
#[CoversClass(SettingsFormBase::class)]
class SettingsFormTest extends StrataKernelTestBase
{
	#region Construction

	#[Test]
	#[TestDox('every routed settings form takes the engine when the container holds one')]
	#[Group('strata/form')]
	public function everyFormTakesTheEngineWhenItExists(): void
	{
		$engine = $this->container->get('strata.engine');

		$this->assertInstanceOf(Engine::class, $engine);

		foreach ($this->routedForms() as $route => $class) {
			$form = $class::create($this->container);

			$this->assertInstanceOf(FormInterface::class, $form);

			if (!property_exists($form, 'engine')) {
				continue;
			}

			$this->assertSame(
				$engine,
				(new ReflectionProperty($form, 'engine'))->getValue($form),
				sprintf(
					'%s holds the engine, so submitForm() can reset it and a saved bucket takes ' .
						'effect rather than waiting for the next request',
					$route,
				),
			);
		}
	}

	#[Test]
	#[TestDox('every routed settings form still builds when the module owns no services')]
	#[Group('strata/form')]
	public function everyFormBuildsWithoutTheEngine(): void
	{
		$container = $this->containerWithoutStrata();

		$this->assertFalse($container->has('strata.engine'), 'the fixture holds no engine');

		foreach ($this->routedForms() as $route => $class) {
			$form = $class::create($container);

			$this->assertInstanceOf(
				FormInterface::class,
				$form,
				sprintf(
					'%s builds against a container with no Strata services; a stale router row ' .
						'reaches this class through composer autoloading and must not answer 500',
					$route,
				),
			);

			if (property_exists($form, 'engine')) {
				$this->assertNull((new ReflectionProperty($form, 'engine'))->getValue($form));
			}
		}
	}

	#endregion

	#region Validation

	#[Test]
	#[TestDox('the storage form refuses a codec this host cannot compress a frame with')]
	#[Group('strata/form')]
	public function theStorageFormRefusesACodecTheHostCannotRun(): void
	{
		// the select lists an unavailable codec with its reason on purpose, so somebody looking for
		// zstd learns the extension is missing. accepting one is a different thing: it leaves the
		// flush path quietly on another codec and turns a form error into a health finding
		$errors = $this->errorsFrom(['codec__id' => 'gone_from_this_host']);

		$this->assertArrayHasKey('codec__id', $errors);
		$this->assertStringContainsString('gone_from_this_host', $errors['codec__id']);
	}

	#[Test]
	#[TestDox('best available is accepted, since it names no codec to be missing')]
	#[Group('strata/form')]
	public function theStorageFormAcceptsBestAvailable(): void
	{
		$this->assertArrayNotHasKey('codec__id', $this->errorsFrom(['codec__id' => '']));
	}

	#[Test]
	#[TestDox('a codec this host can write with is accepted')]
	#[Group('strata/form')]
	public function theStorageFormAcceptsAUsableCodec(): void
	{
		$available = CodecRegistry::withShippedCodecs()->available();

		$this->assertNotSame([], $available, 'a host always has at least one writer');

		foreach ($available as $id) {
			$this->assertArrayNotHasKey(
				'codec__id',
				$this->errorsFrom(['codec__id' => $id]),
				sprintf('%s can write here, so the form accepts it', $id),
			);
		}
	}

	/**
	 * Submits the storage form and returns whatever it refused.
	 *
	 * Driven through the form builder rather than a browser, because a browser cannot select an
	 * option a host does not offer and which options exist depends on the host.
	 *
	 * @param array<string, mixed> $values
	 *   Values to submit on top of a configuration that otherwise validates.
	 *
	 * @return array<string, string>
	 *   Field name keyed to the error, empty when the form accepted everything.
	 */
	private function errorsFrom(array $values): array
	{
		$state = new FormState();
		$state->setValues(
			$values + ['cipher__id' => 'none', 'provider' => 'null', 'flush__max_age' => 15],
		);

		$class = StorageSettingsForm::class;
		$this->container->get('form_builder')->submitForm($class, $state);

		return array_map('strval', $state->getErrors());
	}

	#endregion

	#region Fixtures

	/**
	 * A container holding what a form autowires and nothing this module declares.
	 *
	 * Shaped like the container a site has when the module has left `core.extension` while its
	 * routes are still in the router table: core is present, `strata.*` is not.
	 *
	 * @return ContainerBuilder
	 *   The container.
	 */
	private function containerWithoutStrata(): ContainerBuilder
	{
		$container = new ContainerBuilder();

		$container->set('config.factory', $this->container->get('config.factory'));
		$container->set('config.typed', $this->container->get('config.typed'));
		$container->set(ConfigFactoryInterface::class, $this->container->get('config.factory'));
		$container->set(TypedConfigManagerInterface::class, $this->container->get('config.typed'));
		$container->set('string_translation', $this->container->get('string_translation'));

		return $container;
	}

	/**
	 * Every form class the root module routes to.
	 *
	 * Read from the routing file rather than listed, so a form added tomorrow is covered the day it
	 * is routed.
	 *
	 * @return array<string, class-string<FormInterface>>
	 *   Route name keyed to the form class it names.
	 */
	private function routedForms(): array
	{
		$file = dirname(__DIR__, 3) . '/strata.routing.yml';
		$routes = Yaml::decode((string) file_get_contents($file));

		$this->assertIsArray($routes);

		$forms = [];

		foreach ($routes as $name => $route) {
			$class = (string) ($route['defaults']['_form'] ?? '');

			if ($class === '') {
				continue;
			}

			$this->assertTrue(class_exists($class), sprintf('%s names a class that exists', $name));
			$this->assertTrue(is_subclass_of($class, FormInterface::class));

			$forms[(string) $name] = $class;
		}

		$this->assertNotSame([], $forms, 'the root module routes to at least one form');

		return $forms;
	}

	#endregion
}
