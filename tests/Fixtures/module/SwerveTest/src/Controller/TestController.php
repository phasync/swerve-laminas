<?php

namespace SwerveTest\Controller;

use Laminas\Authentication\Adapter\Callback;
use Laminas\Authentication\AuthenticationService;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use Laminas\Filter\File\RenameUpload;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\Http\Response\Stream;
use Laminas\InputFilter\FileInput;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Session\Container;
use Laminas\Session\SessionManager;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\Virtual;
use Swerve\Http\WebSocket;
use Swerve\Swerve;
use SwerveTest\Live;
use SwerveTest\RequestScoped;

/** The routes the tests use, /test/<action>[/<value>], written as a Laminas application would. */
class TestController extends AbstractActionController
{
    public function __construct(private RequestScoped $scoped, private AuthenticationService $auth)
    {
    }

    /** Let other requests run: sleep() waits as a coroutine with phasync-ext, phasync::sleep() without. */
    private static function wait(float $seconds): void
    {
        \extension_loaded('phasync') ? \usleep((int) ($seconds * 1_000_000)) : \phasync::sleep($seconds);
    }

    public function jsonAction()
    {
        return new JsonModel(['framework' => 'laminas', 'ok' => true]);
    }

    /** A wait of ?ms= (default 10) in usleep(), as a database query waits: it blocks the worker without phasync-ext. */
    public function usleepAction()
    {
        $ms = (int) $this->params()->fromQuery('ms', 10);
        \usleep(1000 * $ms);

        return new JsonModel(['waited' => $ms]);
    }

    public function formAction()
    {
        $form = new Form('contact');
        $form->add(new Element\Text('name'));
        $form->add(new Element\Csrf('csrf'));
        if (!$this->getRequest()->isPost()) {
            return (new ViewModel(['form' => $form]))->setTemplate('swerve-test/test/form')->setTerminal(true);
        }
        $form->setData($this->getRequest()->getPost());
        if (!$form->isValid()) {
            $this->getResponse()->setStatusCode(400);

            return new JsonModel(['errors' => $form->getMessages()]);
        }

        return new JsonModel(['name' => $form->getData()['name']]);
    }

    public function jsonPostAction()
    {
        return new JsonModel(['received' => \json_decode($this->getRequest()->getContent(), true)]);
    }

    public function uploadAction()
    {
        $form = new Form('upload');
        $form->add(new Element\Text('title'));
        $form->add(new Element\File('file'));
        $file = new FileInput('file');
        // RenameUpload moves PSR-7 uploads with moveTo(), and needs PSR-17 factories for them
        $file->getFilterChain()->attach(new RenameUpload([
            'target'              => \sys_get_temp_dir() . '/swerve-laminas-upload',
            'randomize'           => true,
            'stream_factory'      => new StreamFactory(),
            'upload_file_factory' => new UploadedFileFactory(),
        ]));
        $form->getInputFilter()->add($file);
        $form->setData(\array_merge_recursive($this->getRequest()->getPost()->toArray(), $this->getRequest()->getFiles()->toArray()));
        if (!$form->isValid()) {
            $this->getResponse()->setStatusCode(400);

            return new JsonModel(['errors' => $form->getMessages()]);
        }
        $data  = $form->getData();
        $moved = $data['file'];
        $path  = $moved->getStream()->getMetadata('uri');
        $sha1  = \sha1_file($path);
        \unlink($path);

        return new JsonModel(['title' => $data['title'], 'name' => $moved->getClientFilename(), 'size' => $moved->getSize(), 'sha1' => $sha1]);
    }

    /** Store the value in every request-scoped place, let other requests run, and read it all back. */
    public function isolationAction()
    {
        $value          = $this->params()->fromRoute('value');
        $session        = new Container('isolation');
        $session->value = $value;
        $this->auth->getStorage()->write("user-$value");
        $this->scoped->value = $value;

        self::wait(0.3);

        return new JsonModel([
            'route'    => $this->params()->fromRoute('value'),
            'url'      => $this->url()->fromRoute(null, [], [], true),
            'query'    => $this->params()->fromQuery('v'),
            'header'   => $this->getRequest()->getHeader('X-Value', null)?->getFieldValue(),
            'server'   => $_SERVER['HTTP_X_VALUE'] ?? null,
            'session'  => $session->value,
            'identity' => $this->auth->getIdentity(),
            'service'  => $this->scoped->value,
        ]);
    }

    /**
     * For the interleaving tests: put the route's value in each place a request's state can
     * live, wait ?ms= (default 50) so that other requests run, and read each back. The
     * superglobals are read both before and after the wait; the session parts need laminas-session.
     */
    public function interleaveAction()
    {
        $value    = (string) $this->params()->fromRoute('value');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $helpers  = $services->get('ViewHelperManager');
        $globals  = static fn () => [
            'get'    => $_GET['v'] ?? null,
            'post'   => $_POST['v'] ?? null,
            'cookie' => $_COOKIE['c'] ?? null,
            'server' => $_SERVER['HTTP_X_VALUE'] ?? null,
        ];
        $before  = $globals();
        $session = $services->has(SessionManager::class) ? new Container('interleave') : null;
        if ($session) {
            $session->value = $value;
            $sessionId      = \session_id();
        }
        $helpers->get('headTitle')($value);
        $this->layout()->setVariable('value', $value);
        $doctype = $this->params()->fromQuery('doctype');
        if ($doctype) {
            $helpers->get('doctype')($doctype);
        }
        \Locale::setDefault((string) $this->params()->fromQuery('locale', 'en_US'));

        self::wait((int) $this->params()->fromQuery('ms', 50) / 1000);

        return new JsonModel([
            'before'     => $before,
            'after'      => $globals(),
            'request'    => $this->params()->fromQuery('v'),
            'head-title' => \strip_tags($helpers->get('headTitle')->renderTitle()),
            'layout'     => $this->layout()->getVariable('value'),
            'server-url' => $helpers->get('serverUrl')(),
            'doctype'    => $helpers->get('doctype')->getDoctype(),
            'locale'     => \Locale::getDefault(),
        ] + ($session ? [
            'session'         => $session->value,
            'native-session'  => $_SESSION['interleave']['value'] ?? null,
            'same-session-id' => \session_id() === $sessionId,
            'late-container'  => (new Container('interleave'))->value,
            'default-manager' => Container::getDefaultManager() === $services->get(SessionManager::class),
        ] : []));
    }

    /** PHP's own session, without laminas-session: the value in $_SESSION, a wait, and back. */
    public function nativeSessionAction()
    {
        \session_start();
        $_SESSION['value'] = $this->params()->fromRoute('value');
        $id                = \session_id();
        self::wait((int) $this->params()->fromQuery('ms', 50) / 1000);

        return new JsonModel(['session' => $_SESSION['value'], 'same-session-id' => \session_id() === $id]);
    }

    /** What the visitor's session holds. */
    public function peekAction()
    {
        $session = new Container('isolation');

        return new JsonModel(['session' => $session->value, 'identity' => $this->auth->getIdentity()]);
    }

    public function counterAction()
    {
        $session        = new Container('counter');
        $session->count = ($session->count ?? 0) + 1;

        return new JsonModel(['count' => $session->count, 'pid' => \getmypid()]);
    }

    public function flashAction()
    {
        $this->flashMessenger()->addSuccessMessage('Saved');

        return $this->redirect()->toRoute('test', ['action' => 'flashes']);
    }

    public function flashesAction()
    {
        return new JsonModel(['messages' => $this->flashMessenger()->getSuccessMessages()]);
    }

    public function loginAction()
    {
        $post    = $this->getRequest()->getPost();
        $adapter = new Callback(static fn ($identity, $password) => 'secret' === $password ? $identity : false);
        $adapter->setIdentity($post['user'])->setCredential($post['password']);
        $result = $this->auth->authenticate($adapter);
        if (!$result->isValid()) {
            $this->getResponse()->setStatusCode(401);

            return new JsonModel(['user' => null]);
        }
        // Against session fixation, as applications do on login
        Container::getDefaultManager()->regenerateId();

        return new JsonModel(['user' => $this->auth->getIdentity()]);
    }

    public function whoamiAction()
    {
        return new JsonModel(['user' => $this->auth->getIdentity()]);
    }

    public function logoutAction()
    {
        $this->auth->clearIdentity();

        return new JsonModel(['user' => null]);
    }

    /** A Laminas stream response whose second line is written half a second after the first. */
    public function streamAction()
    {
        [$read, $write] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \stream_set_blocking($read, false);
        \phasync::go(static function () use ($write) {
            \fwrite($write, "first\n");
            \phasync::sleep(0.5);
            \fwrite($write, "last\n");
            \fclose($write);
        });
        $response = new Stream();
        $response->setStream($read);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain');

        return $response;
    }

    /** A file download, the file deleted after it was sent. */
    public function downloadAction()
    {
        $path = \tempnam(\sys_get_temp_dir(), 'swerve-laminas-download');
        \file_put_contents($path, \str_repeat('0123456789', 100_000));
        $response = new Stream();
        $response->setStream(\fopen($path, 'r'));
        $response->setStreamName($path);
        $response->setCleanup(true);
        $response->setContentLength(1_000_000);
        $response->getHeaders()->addHeaders(['Content-Type' => 'application/octet-stream', 'Content-Length' => '1000000', 'X-Path' => $path]);

        return $response;
    }

    /** Text and binary echo. */
    public function websocketAction()
    {
        return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), static function (WebSocket $ws) {
            foreach ($ws as $message) {
                $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
            }
        });
    }

    /**
     * Server push: forwards the news topic, and nothing else. It says "ready <pid>" once
     * subscribed, so that the tests publish when every client listens.
     */
    public function newsAction()
    {
        return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), static function (WebSocket $ws) {
            ++Live::$callbacks;
            try {
                $news = Swerve::subscribe('news');
                $ws->send('ready ' . \getmypid());
                foreach ($news as $message) {
                    $ws->send($message);
                }
            } finally {
                --Live::$callbacks;
            }
        });
    }

    /** As newsAction(), with a closure bound to the controller, as a closure in a method is. */
    public function newsBoundAction()
    {
        return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), function (WebSocket $ws) {
            ++Live::$callbacks;
            try {
                $news = Swerve::subscribe('news');
                $ws->send('ready ' . \getmypid());
                foreach ($news as $message) {
                    $ws->send($message);
                }
            } finally {
                --Live::$callbacks;
            }
        });
    }

    /** An ordinary route that publishes to the news topic. */
    public function publishAction()
    {
        Swerve::publish('news', (string) $this->params()->fromQuery('m'));

        return new JsonModel(['published' => true]);
    }

    /** The WebSocket callbacks running in this worker. */
    public function liveAction()
    {
        return new JsonModel(['callbacks' => Live::$callbacks, 'pid' => \getmypid()]);
    }

    /**
     * The user, taken from the request before WebSocket::from(). "inside" reads it in the
     * callback instead, which is wrong: the session belongs to whichever request the worker
     * runs at that moment, or to none.
     */
    public function identityAction()
    {
        $user = $this->auth->getIdentity();
        $auth = $this->auth;

        return WebSocket::from($this->getRequest()->getMetadata(ServerRequestInterface::class), static function (WebSocket $ws) use ($user, $auth) {
            foreach ($ws as $message) {
                $ws->send(\json_encode('inside' === $message ? ['user' => $auth->getIdentity()] : ['user' => $user]));
            }
        });
    }

    public function slowAction()
    {
        self::wait(1);

        return new JsonModel(['slow' => 'done']);
    }

    public function memoryAction()
    {
        \gc_collect_cycles();

        return new JsonModel(['memory' => \memory_get_usage(), 'phasync-ext' => \extension_loaded('phasync'), 'virtual' => Virtual::available()]);
    }

    public function throwAction()
    {
        throw new \RuntimeException('Thrown on purpose');
    }
}
