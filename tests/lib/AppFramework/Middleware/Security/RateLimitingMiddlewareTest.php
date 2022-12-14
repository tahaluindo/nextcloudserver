<?php
/**
 * @copyright Copyright (c) 2017 Lukas Reschke <lukas@statuscode.ch>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace Test\AppFramework\Middleware\Security;

use OC\AppFramework\Middleware\Security\RateLimitingMiddleware;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OC\Security\RateLimiting\Exception\RateLimitExceededException;
use OC\Security\RateLimiting\Limiter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Test\TestCase;

/**
 * @group DB
 */
class RateLimitingMiddlewareTest extends TestCase {
	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;
	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;
	/** @var ControllerMethodReflector|\PHPUnit\Framework\MockObject\MockObject */
	private $reflector;
	/** @var Limiter|\PHPUnit\Framework\MockObject\MockObject */
	private $limiter;
	/** @var RateLimitingMiddleware */
	private $rateLimitingMiddleware;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->reflector = $this->createMock(ControllerMethodReflector::class);
		$this->limiter = $this->createMock(Limiter::class);

		$this->rateLimitingMiddleware = new RateLimitingMiddleware(
			$this->request,
			$this->userSession,
			$this->reflector,
			$this->limiter
		);
	}

	public function testBeforeControllerWithoutAnnotation() {
		$this->reflector
			->expects($this->exactly(4))
			->method('getAnnotationParameter')
			->withConsecutive(
				['AnonRateThrottle', 'limit'],
				['AnonRateThrottle', 'period'],
				['UserRateThrottle', 'limit'],
				['UserRateThrottle', 'period']
			)
			->willReturnMap([
				['AnonRateThrottle', 'limit', ''],
				['AnonRateThrottle', 'period', ''],
				['UserRateThrottle', 'limit', ''],
				['UserRateThrottle', 'period', ''],
			]);

		$this->limiter
			->expects($this->never())
			->method('registerUserRequest');
		$this->limiter
			->expects($this->never())
			->method('registerAnonRequest');

		/** @var Controller|\PHPUnit\Framework\MockObject\MockObject $controller */
		$controller = $this->createMock(Controller::class);
		$this->rateLimitingMiddleware->beforeController($controller, 'testMethod');
	}

	public function testBeforeControllerForAnon() {
		/** @var Controller|\PHPUnit\Framework\MockObject\MockObject $controller */
		$controller = $this->createMock(Controller::class);
		$this->request
			->expects($this->once())
			->method('getRemoteAddress')
			->willReturn('127.0.0.1');

		$this->reflector
			->expects($this->exactly(4))
			->method('getAnnotationParameter')
			->withConsecutive(
				['AnonRateThrottle', 'limit'],
				['AnonRateThrottle', 'period'],
				['UserRateThrottle', 'limit'],
				['UserRateThrottle', 'period']
			)
			->willReturnMap([
				['AnonRateThrottle', 'limit', '100'],
				['AnonRateThrottle', 'period', '10'],
				['UserRateThrottle', 'limit', ''],
				['UserRateThrottle', 'period', ''],
			]);

		$this->limiter
			->expects($this->never())
			->method('registerUserRequest');
		$this->limiter
			->expects($this->once())
			->method('registerAnonRequest')
			->with(get_class($controller) . '::testMethod', '100', '10', '127.0.0.1');


		$this->rateLimitingMiddleware->beforeController($controller, 'testMethod');
	}

	public function testBeforeControllerForLoggedIn() {
		/** @var Controller|\PHPUnit\Framework\MockObject\MockObject $controller */
		$controller = $this->createMock(Controller::class);
		/** @var IUser|\PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);

		$this->userSession
			->expects($this->once())
			->method('isLoggedIn')
			->willReturn(true);
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->reflector
			->expects($this->exactly(4))
			->method('getAnnotationParameter')
			->withConsecutive(
				['AnonRateThrottle', 'limit'],
				['AnonRateThrottle', 'period'],
				['UserRateThrottle', 'limit'],
				['UserRateThrottle', 'period']
			)
			->willReturnMap([
				['AnonRateThrottle', 'limit', ''],
				['AnonRateThrottle', 'period', ''],
				['UserRateThrottle', 'limit', '100'],
				['UserRateThrottle', 'period', '10'],
			]);

		$this->limiter
			->expects($this->never())
			->method('registerAnonRequest');
		$this->limiter
			->expects($this->once())
			->method('registerUserRequest')
			->with(get_class($controller) . '::testMethod', '100', '10', $user);


		$this->rateLimitingMiddleware->beforeController($controller, 'testMethod');
	}

	public function testBeforeControllerAnonWithFallback() {
		/** @var Controller|\PHPUnit\Framework\MockObject\MockObject $controller */
		$controller = $this->createMock(Controller::class);
		$this->request
			->expects($this->once())
			->method('getRemoteAddress')
			->willReturn('127.0.0.1');

		$this->userSession
			->expects($this->once())
			->method('isLoggedIn')
			->willReturn(false);

		$this->reflector
			->expects($this->exactly(4))
			->method('getAnnotationParameter')
			->withConsecutive(
				['AnonRateThrottle', 'limit'],
				['AnonRateThrottle', 'period'],
				['UserRateThrottle', 'limit'],
				['UserRateThrottle', 'period']
			)
			->willReturnMap([
				['AnonRateThrottle', 'limit', '200'],
				['AnonRateThrottle', 'period', '20'],
				['UserRateThrottle', 'limit', '100'],
				['UserRateThrottle', 'period', '10'],
			]);

		$this->limiter
			->expects($this->never())
			->method('registerUserRequest');
		$this->limiter
			->expects($this->once())
			->method('registerAnonRequest')
			->with(get_class($controller) . '::testMethod', '200', '20', '127.0.0.1');

		$this->rateLimitingMiddleware->beforeController($controller, 'testMethod');
	}


	public function testAfterExceptionWithOtherException() {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('My test exception');

		/** @var Controller|\PHPUnit\Framework\MockObject\MockObject $controller */
		$controller = $this->createMock(Controller::class);

		$this->rateLimitingMiddleware->afterException($controller, 'testMethod', new \Exception('My test exception'));
	}

	public function testAfterExceptionWithJsonBody() {
		/** @var Controller|\PHPUnit\Framework\MockObject\MockObject $controller */
		$controller = $this->createMock(Controller::class);
		$this->request
			->expects($this->once())
			->method('getHeader')
			->with('Accept')
			->willReturn('JSON');

		$result = $this->rateLimitingMiddleware->afterException($controller, 'testMethod', new RateLimitExceededException());
		$expected = new DataResponse([], 429
		);
		$this->assertEquals($expected, $result);
	}

	public function testAfterExceptionWithHtmlBody() {
		/** @var Controller|\PHPUnit\Framework\MockObject\MockObject $controller */
		$controller = $this->createMock(Controller::class);
		$this->request
			->expects($this->once())
			->method('getHeader')
			->with('Accept')
			->willReturn('html');

		$result = $this->rateLimitingMiddleware->afterException($controller, 'testMethod', new RateLimitExceededException());
		$expected = new TemplateResponse(
			'core',
			'429',
			[],
			TemplateResponse::RENDER_AS_GUEST
		);
		$expected->setStatus(429);
		$this->assertEquals($expected, $result);
		$this->assertIsString($result->render());
	}
}
