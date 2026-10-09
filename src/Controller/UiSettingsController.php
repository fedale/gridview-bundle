<?php

namespace Fedale\GridviewBundle\Controller;

use Fedale\GridviewBundle\Theme\ThemeRegistry;
use Fedale\GridviewBundle\UiSettings\GridDescriptor;
use Fedale\GridviewBundle\UiSettings\GridRegistry;
use Fedale\GridviewBundle\UiSettings\UiSettingInterface;
use Fedale\GridviewBundle\UiSettings\UiSettingsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
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
 * The host app imports the route with its own prefix, e.g.
 * `resource: '@FedaleGridviewBundle/src/Controller/UiSettingsController.php'`,
 * `type: attribute`, `prefix: /gridview/_settings`.
 */
final class UiSettingsController
{
    private const DOMAIN = 'GridviewBundle';

    /**
     * @param array<string, mixed> $bundleConfig the processed `fedale_gridview` config
     */
    public function __construct(
        private readonly UiSettingsResolver $resolver,
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
        if (!$this->resolver->isEnabled()) {
            throw new NotFoundHttpException('UI settings are disabled: set fedale_gridview.ui_settings.store.');
        }

        $scope = (string) $request->query->get('scope', UiSettingsResolver::GLOBAL_SCOPE);
        $grid = null;
        if ($scope !== UiSettingsResolver::GLOBAL_SCOPE) {
            $grid = $this->grids->get($scope) ?? throw new NotFoundHttpException(sprintf('Unknown grid "%s".', $scope));
        }

        $settings = $this->resolver->settingsFor($grid);
        $form = $this->buildForm($settings, $grid, $scope);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resolver->save($scope, (array) $form->getData());

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
     * One optional choice field per setting. The empty choice means "inherit":
     * from the code configuration in the global scope, from the global value
     * (named in the placeholder) in a grid scope.
     *
     * @param array<string, UiSettingInterface> $settings
     */
    private function buildForm(array $settings, ?GridDescriptor $grid, string $scope): FormInterface
    {
        $builder = $this->formFactory->createNamedBuilder(
            'gv_ui_settings',
            FormType::class,
            $this->resolver->values($scope),
            [
                'action' => $this->urlGenerator->generate('fedale_gridview_ui_settings', ['scope' => $scope]),
                'translation_domain' => false,
            ],
        );

        $global = $this->resolver->values(UiSettingsResolver::GLOBAL_SCOPE);
        foreach ($settings as $key => $setting) {
            $choices = [];
            foreach ($setting->choices($grid) as $labelKey => $value) {
                $choices[$this->trans($labelKey)] = $value;
            }

            $builder->add($key, ChoiceType::class, [
                'label' => $this->trans($setting->label()),
                'help' => $setting->help() !== null ? $this->trans($setting->help()) : null,
                'choices' => $choices,
                'required' => false,
                'placeholder' => $this->placeholder($setting, $grid, $global),
                'choice_translation_domain' => false,
            ]);
        }

        return $builder->getForm();
    }

    /** @param array<string, mixed> $global */
    private function placeholder(UiSettingInterface $setting, ?GridDescriptor $grid, array $global): string
    {
        if ($grid === null) {
            return $this->trans('ui_settings.inherit_code');
        }

        $value = $global[$setting->key()] ?? null;
        $label = array_search($value, $setting->choices(null), true);
        if ($value === null || $label === false || !$setting->isApplicable($value, $grid->options)) {
            return $this->trans('ui_settings.inherit_code');
        }

        return $this->translator->trans('ui_settings.inherit_global', ['%value%' => $this->trans((string) $label)], self::DOMAIN);
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
