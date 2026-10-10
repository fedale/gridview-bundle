<?php

namespace Fedale\GridviewBundle\Controller;

use Fedale\GridviewBundle\Theme\ThemeRegistry;
use Fedale\GridviewBundle\UiSettings\GridDescriptor;
use Fedale\GridviewBundle\UiSettings\GridRegistry;
use Fedale\GridviewBundle\UiSettings\UiSettingInterface;
use Fedale\GridviewBundle\UiSettings\UiSettingsResolver;
use Fedale\SettingBundle\Exception\SettingValidationException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Body of the UI settings modal, served inside the `gv-ui-settings` turbo-frame.
 *
 * GET renders the form of one scope (`?scope=_global` or `?scope=<gridId>`);
 * POST saves it and redirects back (Post/Redirect/Get, with `saved=1` so the
 * client controller reloads the grids on the page). An invalid submission is
 * re-rendered with a 422, which Turbo swaps into the frame.
 *
 * Answers 404 while the feature is off: fedale/setting-bundle not installed
 * (no resolver) or its scoped store not configured.
 *
 * The host app imports the route with its own prefix, e.g.
 * `resource: '@FedaleGridviewBundle/src/Controller/UiSettingsController.php'`,
 * `type: attribute`, `prefix: /gridview/_settings`.
 */
final class UiSettingsController
{
    private const DOMAIN = 'GridviewBundle';

    /**
     * @param UiSettingsResolver|null $resolver     null when fedale/setting-bundle is not installed
     * @param array<string, mixed>    $bundleConfig the processed `fedale_gridview` config
     */
    public function __construct(
        private readonly ?UiSettingsResolver $resolver,
        private readonly GridRegistry $grids,
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ThemeRegistry $themes,
        private readonly array $bundleConfig,
    ) {
    }

    #[Route('', name: 'fedale_gridview_ui_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $resolver = $this->resolver;
        if ($resolver === null || !$resolver->isEnabled()) {
            throw new NotFoundHttpException('UI settings are disabled: install fedale/setting-bundle and set fedale_setting.scoped.store.');
        }

        $scope = (string) $request->query->get('scope', UiSettingsResolver::GLOBAL_SCOPE);
        $grid = null;
        if ($scope !== UiSettingsResolver::GLOBAL_SCOPE) {
            $grid = $this->grids->get($scope) ?? throw new NotFoundHttpException(sprintf('Unknown grid "%s".', $scope));
        }

        $settings = $resolver->settingsFor($grid);
        $form = $this->buildForm($resolver, $settings, $grid, $scope);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->save($resolver, $form, $scope, $grid)) {
            return new RedirectResponse(
                $this->urlGenerator->generate('fedale_gridview_ui_settings', ['scope' => $scope, 'saved' => 1]),
                Response::HTTP_SEE_OTHER,
            );
        }

        $html = $this->twig->render('@FedaleGridview/ui_settings/_frame.html.twig', [
            'form' => $form->createView(),
            'scope' => $scope,
            'scopes' => $this->scopes(),
            'grid' => $grid,
            'hasSettings' => $settings !== [],
            'saved' => $request->query->getBoolean('saved'),
            'cls' => $this->themes->classMap((string) ($this->bundleConfig['theme'] ?? 'default')),
            'formTheme' => $this->bundleConfig['defaults']['behavior']['formTheme'] ?? '@FedaleGridview/form/gv_form_theme.html.twig',
        ]);

        return new Response($html, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * Saves the submitted values; a value setting-bundle rejects (e.g. a
     * constraint of the setting) becomes an error on its field.
     */
    private function save(UiSettingsResolver $resolver, FormInterface $form, string $scope, ?GridDescriptor $grid): bool
    {
        try {
            $resolver->save($scope, (array) $form->getData(), $grid);
        } catch (SettingValidationException $e) {
            $field = $form->has($e->getKey()) ? $form->get($e->getKey()) : $form;
            foreach ($e->getViolationMessages() as $message) {
                $field->addError(new FormError($message));
            }

            return false;
        }

        return true;
    }

    /**
     * One optional choice field per setting. The empty choice means "inherit",
     * and the placeholder names the value inherited.
     *
     * @param array<string, UiSettingInterface> $settings
     */
    private function buildForm(UiSettingsResolver $resolver, array $settings, ?GridDescriptor $grid, string $scope): FormInterface
    {
        $builder = $this->formFactory->createNamedBuilder(
            'gv_ui_settings',
            FormType::class,
            $resolver->values($scope),
            [
                'action' => $this->urlGenerator->generate('fedale_gridview_ui_settings', ['scope' => $scope]),
                'translation_domain' => false,
            ],
        );

        $inherited = $resolver->inherited($scope, $grid);
        foreach ($settings as $key => $setting) {
            $choices = [];
            foreach ($setting->choicesFor($grid) as $labelKey => $value) {
                $choices[$this->trans($labelKey)] = $value;
            }

            $builder->add($key, ChoiceType::class, [
                'label' => $this->trans($setting->label()),
                'help' => $setting->help() !== null ? $this->trans($setting->help()) : null,
                'choices' => $choices,
                'required' => false,
                'placeholder' => $this->placeholder($setting, $grid, $inherited),
                'choice_translation_domain' => false,
            ]);
        }

        return $builder->getForm();
    }

    /**
     * The "inherit" choice, naming the value the field falls back to when one
     * applies (from the global scope or the platform defaults), else the code
     * configuration.
     *
     * @param array<string, mixed> $inherited
     */
    private function placeholder(UiSettingInterface $setting, ?GridDescriptor $grid, array $inherited): string
    {
        $value = $inherited[$setting->key()] ?? null;
        $label = $value === null ? false : array_search($value, $setting->choicesFor($grid), true);
        if ($label === false) {
            return $this->trans('ui_settings.inherit_code');
        }

        return $this->translator->trans('ui_settings.inherit_value', ['%value%' => $this->trans((string) $label)], self::DOMAIN);
    }

    /** @return array<string, string> scope => label, global first */
    private function scopes(): array
    {
        $domain = $this->bundleConfig['i18n']['client_domain'] ?? 'messages';
        $scopes = [UiSettingsResolver::GLOBAL_SCOPE => $this->trans('ui_settings.scope_global')];
        foreach ($this->grids->all() as $id => $grid) {
            // Grid headings are app-domain keys (e.g. `category.label`) or plain
            // text; an untranslated key falls back to the grid id.
            $label = $grid->label !== null ? $this->translator->trans($grid->label, [], $domain) : $id;
            $scopes[$id] = $label === $grid->label && str_ends_with($label, '.label') ? $id : $label;
        }

        return $scopes;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], self::DOMAIN);
    }
}
