<?php
/**
 * @copyright Copyright (c) 2022 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Theming\Service;

use OCA\Theming\AppInfo\Application;
use OCA\Theming\Themes\DefaultTheme;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class ThemeInjectionService {

	private IURLGenerator $urlGenerator;
	private ThemesService $themesService;
	private DefaultTheme $defaultTheme;
	private IConfig $config;
	private ?string $userId;

	public function __construct(IURLGenerator $urlGenerator,
								ThemesService $themesService,
								DefaultTheme $defaultTheme,
								IConfig $config,
								IUserSession $userSession) {
		$this->urlGenerator = $urlGenerator;
		$this->themesService = $themesService;
		$this->defaultTheme = $defaultTheme;
		$this->config = $config;
		if ($userSession->getUser() !== null) {
			$this->userId = $userSession->getUser()->getUID();
		} else {
			$this->userId = null;
		}
	}

	public function injectHeaders() {
		$themes = $this->themesService->getThemes();
		$defaultTheme = $themes[$this->defaultTheme->getId()];
		$mediaThemes = array_filter($themes, function($theme) {
			// Check if the theme provides a media query
			return (bool)$theme->getMediaQuery();
		});

		// Default theme fallback
		$this->addThemeHeader($defaultTheme->getId());

		// Themes applied by media queries
		foreach($mediaThemes as $theme) {
			$this->addThemeHeader($theme->getId(), true, $theme->getMediaQuery());
		}

		// Themes
		foreach($this->themesService->getThemes() as $theme) {
			// Ignore default theme as already processed first
			if ($theme->getId() === $this->defaultTheme->getId()) {
				continue;
			}
			$this->addThemeHeader($theme->getId(), false);
		}
	}

	/**
	 * Inject theme header into rendered page
	 *
	 * @param string $themeId the theme ID
	 * @param bool $plain request the :root syntax
	 * @param string $media media query to use in the <link> element
	 */
	private function addThemeHeader(string $themeId, bool $plain = true, string $media = null) {
		$cacheBuster = $this->config->getAppValue('theming', 'cachebuster', '0');
		if ($this->userId !== null) {
			// need to bust the cache for the CSS file when the user background changed as its
			// URL is served in those files
			$userCacheBuster = $this->config->getUserValue($this->userId, Application::APP_ID, 'userCacheBuster', '0');
			$cacheBuster .= $this->userId . '_' . $userCacheBuster;
		}

		$linkToCSS = $this->urlGenerator->linkToRoute('theming.Theming.getThemeStylesheet', [
			'themeId' => $themeId,
			'plain' => $plain,
			'v' => substr(sha1($cacheBuster), 0, 8),
		]);
		Util::addHeader('link', [
			'rel' => 'stylesheet',
			'media' => $media,
			'href' => $linkToCSS,
			'class' => 'theme'
		]);
	}
}
