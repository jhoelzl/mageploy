<?php

if (file_exists('abstract.php')) {
    require_once 'abstract.php';
} else {
    require_once 'shell/abstract.php';
}

class Mage_Shell_Mageploy extends Mage_Shell_Abstract {
    
    const TERM_COLOR_RED    = '31m';
    const TERM_COLOR_GREEN  = '32m';
    const TERM_COLOR_YELLOW = '33m';
    

    protected $_options = array(
        'track <val>' => '0 to disable tracking, any other value to enable it',
        'status' => 'Show if there are any changes to be imported',
        'run <id>' => 'Import changes for specified action (may cause inconsistencies); import all changes with blank id ',
    );
    
    private function __getColoredString($str, $color = null) {
        if (is_null($color)) return $str;
        
        return sprintf("\033[0;%s%s\033[0m", $color, $str);
    }

    private function __getVersion() {
        return Mage::getConfig()->getNode('modules/PugMoRe_Mageploy/version');
    }

    protected function _initSession() {
        $userModel = Mage::getModel('admin/user');
        $userModel->setUserId(0);
        Mage::getSingleton('admin/session')->setUser($userModel);        
        /*
        $session = Mage::getSingleton('admin/session');
        try {
            $user = Mage::getModel('admin/user');
            $user->login('admin_username', 'admin_password');
            if ($user->getId()) {
                $session->renewSession();

                if (Mage::getSingleton('adminhtml/url')->useSecretKey()) {
                    Mage::getSingleton('adminhtml/url')->renewSecretUrls();
                }
                $session->setIsFirstPageAfterLogin(true);
                $session->setUser($user);
                $session->setAcl(Mage::getResourceModel('admin/acl')->loadAcl());
            } else {
                Mage::throwException(Mage::helper('adminhtml')->__('Invalid User Name or Password.'));
            }
        } catch (Mage_Core_Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
        */
    }

    protected function _construct() {
        $this->_io = new PugMoRe_Mageploy_Model_Io_File();
        $this->_initSession('admin', 'p4ssw0rd');
        return parent::_construct();
    }

    protected function _getControllerClassPath($controllerModule, $controllerName) {
        $parts = explode('_', uc_words($controllerName));
        $file = Mage::getModuleDir('controllers', $controllerModule);
        if (count($parts)) {
            $file .= DS . implode(DS, $parts);
        }
        $file .= 'Controller.php';
        return $file;
    }

    public function _getControllerClassName($controllerModule, $controllerName) {
        $class = $controllerModule . '_' . uc_words($controllerName) . 'Controller';
        return $class;
    }

    public function run() {
        $helper = Mage::helper('pugmore_mageploy');

        $track = $this->getArg('track');
        if ($track !== false) {
            if (!strcmp('0', $track)) {
                $doTracking = $helper->disable();
            } else {
                $doTracking = $helper->enable();
            }
        } else {
            $doTracking = $helper->isActive();
        }

        if ($this->getArg('status')) {
            $this->_printHeader($doTracking);

            $pendingList = $this->_io->getPendingList();
            if (count($pendingList)) {
                printf("Pending Actions list:\r\n");
                foreach ($pendingList as $i => $row) {
                    $actionDescr = sprintf("ID: %d\t - %s (%s on %s)", ($i + 1), $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_ACTION_DESCR], $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_ACTION_USER], strftime("%c", $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_ACTION_TIMESTAMP]));

                    $spacer = str_repeat(" ", max(0, 40 - strlen($actionDescr)));
                    printf("%s\r\n", $actionDescr);
                }
                printf("\r\nTotal pending actions: %d\r\n", count($pendingList));
            } else {
                printf("There aren't any pending actions to execute.\r\n");
            }
        } else if ($id = $this->getArg('run')) {
            $this->_printHeader($doTracking);

            $pendingList = $this->_io->getPendingList();
            if (count($pendingList)) {
                $executed = 0;

                $session = Mage::getSingleton('adminhtml/session');
                foreach ($pendingList as $i => $row) {
                    if (($id > 0) && ($i + 1 != $id)) {
                        continue;
                    }

                    $actionExecutorClass = $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_EXECUTOR_CLASS];
                    $controllerModule = $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_CONTROLLER_MODULE];
                    $controllerName = $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_CONTROLLER_NAME];
                    $controllerClassName = $this->_getControllerClassName($controllerModule, $controllerName);
                    if (class_exists($actionExecutorClass)) {
                        $controllerFileName = $this->_getControllerClassPath($controllerModule, $controllerName);
                        if (file_exists($controllerFileName)) {
                            include_once $controllerFileName;
                        } else {
                            printf("Error: file '%s' not found!\r\n", $controllerFileName);
                        }
                        if (class_exists($controllerClassName)) {
                            $actionExecutor = new $actionExecutorClass();
                            $parameters = $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_ACTION_PARAMS];
                            $request = $actionExecutor->decode($parameters);
                            $controller = new $controllerClassName($request, new Mage_Core_Controller_Response_Http());
                            $action = $row[PugMoRe_Mageploy_Model_Action_Abstract::INDEX_ACTION_NAME] . 'Action';
                            $controller->preDispatch();
                            $controller->$action();
                            $controller->postDispatch();

                            $messages = $session->getMessages(clear);
                            foreach ($messages->getItems() as $message) {
                                $color = $message->getType() == 'error' ? self::TERM_COLOR_RED : self::TERM_COLOR_GREEN;

                                printf("Action ID #%d - %s %s\r\n", ($i + 1), $this->__getColoredString($message->getType(), $color), $message->getText());
                            }

                            $executed++;
                            // register executed action
                            $this->_io->done($row);
                        } else {
                            printf("Error: class '%s' not found!\r\n", $controllerClassName);
                        }
                    } else {
                        printf("Error: class '%s' not found!\r\n", $actionExecutorClass);
                    }
                    // Yes, PHP is Object Oriented but don't forget the 
                    // Superglobals! After all PHP is not Java :-)
                    $_GET = array();
                    $_POST = array();
                    $_REQUEST = array();
                }
                printf("\r\nExecuted actions: %d/%d\r\n", $executed, count($pendingList));
            } else {
                printf("There aren't any pending actions to execute.\r\n");
            }
        } else {
            echo $this->usageHelp($doTracking);
        }
        printf("\r\n");
    }

    public function usageHelp($isActive) {
        $this->_printHeader($isActive);

        $help = "Usage:\tphp mageploy.php --[options]\r\n\r\n";
        foreach ($this->_options as $option => $description) {
            $help .= "--$option" . str_repeat(" ", 20 - strlen($option)) . "$description\r\n";
        }
        return $help . "\r\n";
    }

    protected function _printHeader($isActive) {
        $active = $isActive 
            ? $this->__getColoredString("tracking is active", self::TERM_COLOR_GREEN)
            : $this->__getColoredString("tracking is not active", self::TERM_COLOR_RED);
        $user = Mage::helper('pugmore_mageploy')->getUser();
        $user = $this->__getColoredString("user is " . $user, !strcmp('anonymous', $user) ? self::TERM_COLOR_YELLOW : self::TERM_COLOR_GREEN);
        printf("\r\nMageploy v %s - %s - %s\r\n\r\n", $this->__getVersion(), $active, $user);
    }

}

$shell = new Mage_Shell_Mageploy();
$shell->run();
