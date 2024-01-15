<? if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Api\VueWorkerComponent;
use Proactivity\AgenciesCatalog\AgenciesCatalogApiParamsCheck;
use Proactivity\AgenciesCatalog\AgenciesCatalogHelper;
use Proactivity\AgenciesCatalog\AgenciesCatalogUrlParser;

\CBitrixComponent::includeComponentClass('api_vue:vue.worker');

class GetAgenciesCatalog extends VueWorkerComponent {
	public const defaultLimit = 20;

	protected $inputKeyValues = [];
	protected $filterParamsByFilter = [];
	protected $filterParams = [];
	protected $newUrl = [];

	protected function setParams($params): array {
		$result = [
			'limit'             => (isset($params['limit']) && intval($params['limit']) > 0) ? intval($params['limit']) : static::defaultLimit,
			'setMeta'           => isset($params['setMeta']) && !!$params['setMeta'],
			'setParamsByUrl'    => isset($params['setParamsByUrl']) && !!$params['setParamsByUrl'],
			'showSearch'        => !isset($params['showSearch']) || !!$params['showSearch'],
			'showPriceBlock'    => !isset($params['showPriceBlock']) || !!$params['showPriceBlock'],
			'returnInputParams' => isset($params['returnInputParams']) && !!$params['returnInputParams'],
			'getAgenciesList'   => !isset($params['getAgenciesList']) || !!$params['getAgenciesList'],
			'getFilters'        => !isset($params['getFilters']) || !!$params['getFilters'],
		];
		$result['offset'] = (isset($params['offset']) && intval($params['offset']) > 0)
			? intval($params['offset'])
			: $this->getCurrentPage() * $result['limit'];

		if (!$result['setParamsByUrl']) {
			$checkParamsResult = AgenciesCatalogApiParamsCheck::checkParams($params);
			if (!isset($checkParamsResult['filterParams']['tenderServiceCode'])) {
				$this->errors['serviceCode'] = 'Обязательное поле';
			} else {
				$this->setFilterParams($checkParamsResult['filterParams'], $checkParamsResult['inputKeyValues']);
			}
		}

		return $result;
	}

	protected function additionalCheck(): void {
		if ($this->params['setParamsByUrl']) {
			$agenciesCatalogUrlParserInstance = AgenciesCatalogUrlParser::getInstance();
			if ($redirectUrl = $agenciesCatalogUrlParserInstance->getRedirectUrl()) {
				$this->errors['_'] = 'Неверный адрес страницы. Вы будете перенаправлены';
				$this->errors['redirect'] = $redirectUrl == 404 ? '' : $redirectUrl;
			} else {
				$this->setFilterParams($agenciesCatalogUrlParserInstance->getParams(), $agenciesCatalogUrlParserInstance->getInputKeyValues());
			}
		}
	}

	protected function setFilterParams(array $filterParams, array $inputKeyValues) {
		$this->filterParamsByFilter = $filterParams;
		$this->inputKeyValues = $inputKeyValues;
		foreach ($filterParams as $params) {
			foreach ($params as $paramName => $values) {
				$this->filterParams[$paramName] = $values;
			}
		}
	}

	protected function getResult(): array {
		$result = [];

		$result['filter'] = [
			'applyFilterUrl' => AgenciesCatalogHelper::applyFilterUrl,
			'search'         => $this->params['showSearch'],
		];

		if ($this->params['getFilters']) {
			$result['filter']['filters'] = $this->getFilters();
		}

		if ($this->params['getAgenciesList']) {
			$result['agenciesList'] = $this->getAgenciesList();
			$result['seo'] = $this->getSeoTexts($result['agenciesList']['count'] ?? 0);
		}
		if ($this->params['setMeta']) $this->setMeta($result['seo'], $result['agenciesList']['count'] ?? 0);
		if ($this->params['returnInputParams']) $result['inputParams'] = $this->inputKeyValues;
		if ($this->params['showPriceBlock']) $result['priceBlock'] = $this->getPriceBlockParams($result['agenciesList']['count'] ?? 0);

		return $result;
	}

	private function getAgenciesList() {
		global $APPLICATION;

		$result = $APPLICATION->IncludeComponent('api_vue:get.agencies.catalog.list', '', [
			'PARAMS' => [
				'limit'          => $this->params['limit'],
				'offset'         => $this->params['offset'],
				'checkedParams'  => $this->filterParamsByFilter,
				'inputKeyValues' => $this->inputKeyValues,
			],
		]);

		return $result['success'] ? $result['data'] : [];
	}

	private function getFilters() {
		global $APPLICATION;

		$result = $APPLICATION->IncludeComponent('api_vue:get.agencies.catalog.filters', '', [
			'PARAMS' => [
				'checkedParams'  => $this->filterParamsByFilter,
				'inputKeyValues' => $this->inputKeyValues,
			],
		]);

		return $result['success'] ? $result['data'] : [];
	}

	private function getSeoTexts(int $agenciesCount) {
		global $APPLICATION;

		$result = $APPLICATION->IncludeComponent('api_vue:get.agencies.catalog.seo_texts', '', [
			'PARAMS' => [
				'checkedParams'  => $this->filterParamsByFilter,
				'inputKeyValues' => $this->inputKeyValues,
				'agenciesCount'  => $agenciesCount,
			],
		]);

		return $result['success'] ? $result['data'] : [];
	}

	private function getPriceBlockParams(int $agenciesCount) {
		global $APPLICATION;

		$result = $APPLICATION->IncludeComponent('api_vue:get.agencies.catalog.prices', '', [
			'PARAMS' => [
				'serviceCode'    => $this->filterParams['tenderServiceIDs']['input'][0],
				'subServiceCode' => $this->filterParams['tenderSubServiceIDs']['input'][0],
				'cityCode'       => $this->filterParams['cityIDs']['input'][0],
				'countryCode'    => $this->filterParams['countryIDs']['input'][0],
				'agenciesCount'  => $agenciesCount,
			],
		]);

		return $result['success'] ? $result['data'] : [];
	}

	private function setMeta($seo, $agenciesCount): void {
		global $APPLICATION;
		if ($seo['pageTitle']) $APPLICATION->SetTitle($seo['pageTitle']);
		if ($seo['metaTitle']) $APPLICATION->SetPageProperty('title', $seo['metaTitle']);
		if ($seo['metaDescription']) $APPLICATION->SetPageProperty('description', $seo['metaDescription']);
		if ($seo['metaKeywords']) $APPLICATION->SetPageProperty('keywords', $seo['metaKeywords']);

		if (
			$this->filterParams['agencyWithReview'] || $this->filterParams['developmentToolsIDs']
			|| $this->filterParams['agencyHourPriceRange'] || $this->filterParams['staffRange']
		) {
			unset($GLOBALS['arMeta']['robots']);
			$GLOBALS['arMeta'][] = '<meta name="robots" content="noindex, nofollow" />';
		}

		if (
			$this->filterParams['agencyWithReview'] || $this->filterParams['leadProgrammeParticipant'] || $this->filterParams['leadProgrammeParticipant'] || $this->filterParams['developmentToolsIDs']
			|| $this->filterParams['agencyHourPriceRange'] || $this->filterParams['staffRange']
		) {
			unset($GLOBALS['arMeta']['canonical']);
		}

		$pagesParams = $this->getPagesParams($agenciesCount);

		if ($this->params['offset'] > 0 && $pagesParams['total'] > 1 && $pagesParams['current'] > 1 && $pagesParams['current'] <= $pagesParams['total']) {
			$GLOBALS['arMeta'][] = '<link rel="prev" href="' . $APPLICATION->GetCurPageParam($pagesParams['current'] > 2 ? 'page=' . ($pagesParams['current'] - 1) : '', ['page'], null) . '"/>';
		}
		if ($pagesParams['current'] <= $pagesParams['total'] && $pagesParams['total'] > 1) {
			$GLOBALS['arMeta'][] = '<link rel="next" href="' . $APPLICATION->GetCurPageParam('page=' . ($pagesParams['current'] + 1), ['page'], null) . '"/>';
		}

		$this->showOpenGraphImage($seo);
	}

	protected function showOpenGraphImage($seo) {
		setOpenGraphImage($seo['ogImage']
			? \CFile::GetPath($seo['ogImage'])
			: SITE_TEMPLATE_PATH . '/ws_build/images/opengraph/agencies.png');
	}

	private function getPagesParams($agenciesCount): array {
		return [
			'total'   => ceil($agenciesCount / $this->params['limit']),
			'current' => $this->getCurrentPage(),
		];
	}

	private function getCurrentPage(): int {
		return intval($_GET['page']);
	}
}
