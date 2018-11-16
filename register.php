<?php
defined('_JEXEC') or die;

class register_hotfix_2_3_4_5 extends JControllerLegacy
{
    /**
     * @var JApplicationCms
     * @since 1.0.0
     */
    protected $app;

    /**
     * @var JDocument
     * @since 1.0.0
     */
    protected $doc;

    /**
     * @var JUser
     * @since 1.0.0
     */
    protected $user;

    public function __construct(array $config)
    {
        JLoader::register('FDWalletView', FDW_PATH_SITE . '/libraries/view.php');
        JLoader::register('FDWalletModelPaymentUp', FDW_PATH_SITE . '/models/paymentUp.php');
        JLoader::register('FDWalletModelPaymentDown', FDW_PATH_SITE . '/models/paymentDown.php');
        JLoader::register('FDWalletModelHistory', FDW_PATH_SITE . '/models/history.php');
        JLoader::register('FDWalletModelWallet', FDW_PATH_SITE . '/models/wallet.php');

        $this->app  = JFactory::getApplication();
        $this->doc  = JFactory::getDocument();
        $this->user = JFactory::getUser();

        parent::__construct($config);
    }

    public function start($cachable = false, $urlparams = [])
    {
        $input        = JFactory::getApplication()->input;
        $viewName     = $input->get('view', 'start');
        $preValue     = $input->get('value');
        $prePaymentId = $input->get('paymentId');

        if (!$preValue/* || !$prePaymentId*/) {
            $preValue     = null;
            $prePaymentId = null;
        }

        if ($this->user->guest) {
            $this->app->redirect(
                FDWalletCommon::getUserUrlToRedirect(),
                JText::_('COM_FD_WALLET_WALLET_CAN_USE_ONLY_REG_USER'),
                'message'
            );
            return false;
        }

        if (isset($_POST['confirmPayment']) && ($paymentId = $input->get('paymentId', 0))) {
            /** @var FDWalletModelPaymentDown $paymentDownModel */
            $paymentDownModel = $this->getModel('paymentDown');
            $paymentDownModel->loadPayment($paymentId);

            if ($paymentDownModel->getDbUserId() == $this->user->id) {
                $walletModel = $this->getWallet();
                if ($walletModel->isLoaded()) {
                    // Подтверждение оплаты в Магазине
                    $statusConfirm = $paymentDownModel->confirmPayment();
                    switch ($statusConfirm) {
                        case 'ok':
                            $this->app->redirect($paymentDownModel->getDbUrlSuccess(), JText::_('COM_FD_WALLET_PAY_SUCCESS'), 'message');
                            return false;

                        case 'fail':
                            $this->app->enqueueMessage(JText::_('COM_FD_WALLET_PAY_AND_NOT_CONFIRM'), 'error');
                            break;

                        case 'refund':
                            $walletModel->add($paymentDownModel->getDbValue());
                            $this->app->redirect($paymentDownModel->getDbUrlFail());
                            return false;
                    }
                } else {
                    $this->app->enqueueMessage(JText::_('COM_FD_WALLET_PAY_AND_NOT_CONFIRM'), 'error');
                }
            }
        }

        $walletModel = $this->getWallet();

        /** @var FDWalletViewStart $view */
        $view = $this->getView(
            $viewName,
            $this->doc->getType(),
            '',
            [
                'base_path' => $this->basePath,
                'layout'    => $input->get('layout', 'default', 'string')
            ]
        );

        /** @var FDWalletModelHistory $historyModel */
        $historyModel = $this->getModel('history');

        if (!$preValue/* && !$prePaymentId*/) {
            $historyModel->load();
        }

        $view->set('wallet', $walletModel);
        $view->set('history', $historyModel);
        $view->set('preparedValue', $preValue);
        //$view->set('preparedPaymentId', $prePaymentId);
        $view->display();
        return $this;
    }

    public function up($cachable = false, $urlparams = [])
    {
        $input     = JFactory::getApplication()->input;
        $viewName  = $input->get('view', 'up.first');

        switch ($viewName) {
            case 'up.first':
                return $this->upFirst();

            case 'up.confirm':
                return $this->upConfirm();

            case 'up.success':
                return $this->upSuccess();

            case 'up.wait':
                return $this->upWait();

            case 'up.fail':
                return $this->upFail();
        }

        $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DO_NOT_CAN_CREATE_PAYMENT'), 'error');
        return false;
    }

    public function upFirst()
    {
        $input     = JFactory::getApplication()->input;
        $viewName  = $input->get('view', 'up.first');

        if ($this->user->guest) {
            $this->app->redirect(
                FDWalletCommon::getUserUrlToRedirect('up', 'up.first'),
                JText::_('COM_FD_WALLET_WALLET_CAN_USE_ONLY_REG_USER'),
                'message'
            );
            return false;
        }

        $paymentSum    = (float)$input->get('paymentSum', 0);
        $paymentSum    = (int)ceil($paymentSum);
        $paymentSysId  = $input->getString('paymentSysId', null);

        if ($paymentSum < 1) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_INCORRECT_PAYMENT_SUM'), 'error');
            return false;
        }

        if (empty($paymentSysId) || ($paymentSys = FDWalletPayments::get($paymentSysId)) === false) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_INCORRECT_PAYMENT_TYPE'), 'error');
            return false;
        }

        $walletModel = $this->getWallet();
        if (!$walletModel->isLoaded()) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect());
            return false;
        }

        $paymentId = FDWalletModelPaymentUp::create(
            $paymentSysId,
            $paymentSum,
            JText::sprintf('COM_FD_WALLET_UP_COMMENT_IN_DB', $paymentSys->getName())
        );
        if ($paymentId === false) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DO_NOT_CAN_CREATE_PAYMENT'), 'error');
            return false;
        }

        /** @var FDWalletViewUpFirst $view */
        $view = $this->getView(
            $viewName,
            $this->doc->getType(),
            '',
            [
                'base_path' => $this->basePath,
                'layout'    => $input->get('layout', 'default', 'string')
            ]
        );
        $view->set('wallet', $walletModel);
        $view->set('payment', FDWalletPayments::get($paymentSysId));
        $view->set('paymentId', $paymentId);
        $view->set('paymentSum', $paymentSum);
        $view->display();
        return $this;
    }

    public function upConfirm()
    {
        $input        = JFactory::getApplication()->input;
        $paymentSysId = $input->getString('PAYMENT_ID', null);
        $payment      = FDWalletPayments::get($paymentSysId);

        JLog::add('Data Server: ' . json_encode($_SERVER), JLog::INFO, 'fd_wallet');
        JLog::add('Data Request: ' . json_encode($_REQUEST), JLog::INFO, 'fd_wallet');
        JLog::add('Data Post: ' . json_encode($_POST), JLog::INFO, 'fd_wallet');
        JLog::add('Data Get: ' . json_encode($_GET), JLog::INFO, 'fd_wallet');
        JLog::add('Data PHP: ' . file_get_contents('php://input'), JLog::INFO, 'fd_wallet');

        if ($payment === false) {
            header("HTTP/1.0 400 Bad Request");
            exit;
        }

        /** @var FDWalletModelPaymentUp $paymentModel */
        $paymentModel = $this->getModel('paymentUp');

        try {
            $isConfirmPayment = $payment->isConfirmPayment();
        } catch (Exception $e) {
            $isConfirmPayment = false;
            JLog::add('Exception: ' . $e->getMessage(), JLog::ERROR, 'fd_wallet');
        }

        if ($payment->isConfirmPayment()) {
            if ($paymentModel->confirm($payment)) {
                $payment->renderSuccessConfirmRequest();
            } else {
                $payment->renderFailConfirmRequest();
            }

            header("HTTP/1.1 200 OK");
        } else {
            header("HTTP/1.0 400 Bad Request");
            exit;
        }
    }

    public function upSuccess()
    {
        $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_UP_SUCCESS'));
    }

    public function upFail()
    {
        $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_UP_FAIL'));
    }

    public function upWait()
    {
        $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_UP_WAIT'));
    }

    public function down($cachable = false, $urlparams = [])
    {
        $input     = JFactory::getApplication()->input;
        $viewName  = $input->get('view', 'down.first');

        switch ($viewName) {
            case 'down.first':
                return $this->downFirst();

            case 'down.second':
                return $this->downSecond();
        }

        $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DO_NOT_CAN_CREATE_PAYMENT'), 'error');
        return false;
    }

    public function downFirst()
    {
        if (!isset($_SESSION['FD_WALLET_DOWN'])) {
            $_SESSION['FD_WALLET_DOWN'] = $_POST;
        }

        if ($this->user->guest) {
            $this->app->redirect(
                FDWalletCommon::getUserUrlToRedirect('down', 'down.first'),
                JText::_('COM_FD_WALLET_WALLET_CAN_USE_ONLY_REG_USER'),
                'message'
            );
            return false;
        }

        if (isset($_SESSION['FD_WALLET_DOWN'])) {
            $post = $_SESSION['FD_WALLET_DOWN'];
            unset($_SESSION['FD_WALLET_DOWN']);
        } else {
            $post = $_POST;
        }

        /** @var FDWalletModelPaymentDown $paymentModel */
        $paymentModel = $this->getModel('paymentDown');
        $paymentModel->setData($post);

        if (!$paymentModel->validateApp()) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DOWN_INCORRECT_APP'), 'error');
            return false;
        }

        if (!$paymentModel->validateData()) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DOWN_INCORRECT_DATA'), 'error');
            return false;
        }

        if (($paymentId = $paymentModel->createPayment()) !== false) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect('down', 'down.second', ['id' => $paymentId]));
            return false;
        } else {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DOWN_CANT_CREATE_PAYMENT'), 'error');
            return false;
        }
    }

    public function downSecond()
    {
        $input     = JFactory::getApplication()->input;
        $viewName  = $input->get('view', 'down.second');
        $paymentId = (int)$input->get('id');

        // Запрет доступа гостям
        if ($this->user->guest) {
            $this->app->redirect(
                FDWalletCommon::getUserUrlToRedirect('down', 'down.second'),
                JText::_('COM_FD_WALLET_WALLET_CAN_USE_ONLY_REG_USER'),
                'message'
            );
            return false;
        }

        // Проверка ID платежа
        if (empty($paymentId)) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DOWN_CANT_PAY_INVOICE'), 'error');
            return false;
        }

        // Загрузка данных о платеже
        /** @var FDWalletModelPaymentDown $paymentModel */
        $paymentModel = $this->getModel('paymentDown');
        if (!$paymentModel->loadPayment($paymentId) || $paymentModel->getDbStatus() != FDWalletCommon::PAYMENT_DOWN_STATUS_NEW) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_DOWN_CANT_PAY_INVOICE'), 'error');
            return false;
        }

        // Загрузка кошелька пользователя
        $walletModel = $this->getWallet();
        if (!$walletModel->isLoaded()) {
            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_ERROR_LOAD_WALLET'), 'error');
            return false;
        }

        if (!$walletModel->canRemove($paymentModel->getDbValue())) {
            $this->app->enqueueMessage(JText::_('COM_FD_WALLET_NO_MONEY_FOR_OPERATION'), 'error');
        } elseif (isset($_POST['pay'])) {
            // Снятие средств
            if ($walletModel->remove($paymentModel->getDbValue())) {
                // Оплата счета
                $paymentModel->loadPayment($paymentId);
                if ($paymentModel->payPayment()) {
                    // Подтверждение оплаты в Магазине
                    $statusConfirm = $paymentModel->confirmPayment();
                    switch ($statusConfirm) {
                        case 'ok':
                            $this->app->redirect($paymentModel->getDbUrlSuccess(), JText::_('COM_FD_WALLET_PAY_SUCCESS'), 'message');
                            return false;

                        case 'fail':
                            $this->app->redirect(FDWalletCommon::getRouteUrlToRedirect(), JText::_('COM_FD_WALLET_PAY_AND_NOT_CONFIRM'), 'message');
                            return false;

                        case 'refund':
                            $walletModel->add($paymentModel->getDbValue());
                            $this->app->redirect($paymentModel->getDbUrlFail());
                            return false;
                    }
                } else {
                    $walletModel->add($paymentModel->getDbValue());
                    $this->app->redirect($paymentModel->getDbUrlFail());
                    return false;
                }
            } else {
                $this->app->redirect($paymentModel->getDbUrlFail());
                return false;
            }
        }

        /** @var FDWalletViewDownSecond $view */
        $view = $this->getView(
            $viewName,
            $this->doc->getType(),
            '',
            [
                'base_path' => $this->basePath,
                'layout'    => $input->get('layout', 'default', 'string')
            ]
        );

        $view->set('wallet',  $walletModel);
        $view->set('payment', $paymentModel);
        $view->display();
        return $this;
    }

    /**
     * Получение модели кошелька
     * @return FDWalletModelWallet
     * @since 1.0.0
     */
    private function getWallet()
    {
        /** @var FDWalletModelWallet $walletModel */
        $walletModel = $this->getModel('wallet', 'FDWalletModel');
        $walletModel->init($this->user);
        if (!$walletModel->isLoaded()) {
            $this->app->enqueueMessage(JText::_('COM_FD_WALLET_ERROR_LOAD_USER'), 'error');
        }
        return $walletModel;
    }

    public function getView($name = '', $type = '', $prefix = '', $config = [])
    {
        if (strpos($name, '.') !== false) {
            $partsName = explode('.', $name);
            $newName = ucfirst(array_shift($partsName));
            foreach ($partsName as $partName) {
                $newName .= ucfirst($partName);
            }
            $name = $newName;
        }

        if (empty($prefix)) {
            $prefix = 'FDWalletView';
        }

        return parent::getView($name, $type, $prefix, $config);
    }
}
