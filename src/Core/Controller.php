<?php

namespace Nixx\EasyWorkerman\Core;

use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

abstract class Controller {

	public readonly string $locale;
	public ?string $title = null;
	public array $skipBeforeAction = [];

	final public function __construct(public readonly array $params, public readonly Request $request) {
		$this->locale = $this->params['locale'] ?? 'en';
	}

	/**
	 * Выполняет действие перед вызовом action
	 * @return void
	 */
	public function beforeAction(): void {}

	/**
	 * Определяет, является ли запрос от бота
	 * @return bool
	 */
	public function isBot(): bool {
		return preg_match('/Wotbox|googlebot|bingbot|yandex|baiduspider|twitterbot|facebookexternalhit|rogerbot|linkedinbot|embedly|quora link preview|showyoubot|outbrain|pinterest\/0\.|pinterestbot|slackbot|vkShare|W3C_Validator|whatsapp|Mail\.RU_Bot|yahoo-help\.jp|MJ12bot|wget|curl/i', $this->request->header('user-agent'));
	}

	/**
	 * Json response
	 * @param     $data
	 * @param int $options
	 * @return Response
	 */
	protected function json($data, int $options = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE): Response {
		return new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($data, $options));
	}
}
