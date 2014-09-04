<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

use Icinga\Config\PreservingIniWriter;
use Icinga\Web\Controller\ModuleActionController;
use Icinga\Web\Notification;
use Icinga\Form\ConfirmRemovalForm;
use Icinga\Module\Monitoring\Form\Config\BackendForm;
use Icinga\Module\Monitoring\Form\Config\InstanceConfigForm;
use Icinga\Module\Monitoring\Form\Config\SecurityConfigForm;
use Icinga\Exception\NotReadableError;

/**
 * Configuration controller for editing monitoring resources
 */
class Monitoring_ConfigController extends ModuleActionController
{
    /**
     * Display a list of available backends and instances
     */
    public function indexAction()
    {
        $this->view->tabs = $this->Module()->getConfigTabs()->activate('backends');
        foreach (array('backends', 'instances') as $element) {
            try {
                $elementConfig = $this->Config($element);
                if ($elementConfig === null) {
                    $this->view->{$element} = array();
                } else {
                    $this->view->{$element} = $elementConfig->toArray();
                }
            } catch (NotReadableError $e) {
                $this->view->{$element} = $e;
            }
        }
    }

    /**
     * Display a form to modify the backend identified by the 'backend' parameter of the request
     */
    public function editbackendAction()
    {
        // Fetch the backend to be edited
        $backend = $this->getParam('backend');
        $backendsConfig = $this->Config('backends')->toArray();
        if (false === array_key_exists($backend, $backendsConfig)) {
            // TODO: Should behave as in the app's config controller (Specific redirect to an error action)
            Notification::error(sprintf($this->translate('Cannot edit "%s". Backend not found.'), $backend));
            $this->redirectNow('monitoring/config');
        }

        $form = new BackendForm();
        $request = $this->getRequest();
        if ($request->isPost()) {
            if ($form->isValid($request->getPost())) {
                list($newName, $config) = $form->getBackendConfig();

                if ($newName !== $backend) {
                    // Backend name has changed
                    unset($backendsConfig[$backend]); // We can safely use unset as all values are part of the form
                }

                $backendsConfig[$newName] = $config;
                if ($this->writeConfiguration($backendsConfig, 'backends')) {
                    Notification::success(sprintf($this->translate('Backend "%s" successfully modified.'), $backend));
                    $this->redirectNow('monitoring/config');
                } else {
                    $this->render('show-configuration');
                    return;
                }
            }
        } else {
            $form->setBackendConfig($backend, $backendsConfig[$backend]);
        }

        $this->view->form = $form;
    }

    /**
     * Display a form to create a new backend
     */
    public function createbackendAction()
    {
        $form = new BackendForm();
        $request = $this->getRequest();
        if ($request->isPost() && $form->isValid($request->getPost())) {
            list($name, $config) = $form->getBackendConfig();
            $backendsConfig = $this->Config('backends')->toArray();
            $backendsConfig[$name] = $config;
            if ($this->writeConfiguration($backendsConfig, 'backends')) {
                Notification::success(sprintf($this->translate('Backend "%s" created successfully.'), $name));
                $this->redirectNow('monitoring/config');
            } else {
                $this->render('show-configuration');
                return;
            }
        }

        $this->view->form = $form;
    }

    /**
     * Display a confirmation form to remove the backend identified by the 'backend' parameter
     */
    public function removebackendAction()
    {
        $backend = $this->getParam('backend');
        $backendsConfig = $this->Config('backends')->toArray();
        if (false === array_key_exists($backend, $backendsConfig)) {
            // TODO: Should behave as in the app's config controller (Specific redirect to an error action)
            Notification::error(sprintf($this->translate('Cannot remove "%s". Backend not found.'), $backend));
            $this->redirectNow('monitoring/config');
        }

        $form = new ConfirmRemovalForm();
        $request = $this->getRequest();
        if ($request->isPost() && $form->isValid($request->getPost())) {
            unset($backendsConfig[$backend]);
            if ($this->writeConfiguration($backendsConfig, 'backends')) {
                Notification::success(sprintf($this->translate('Backend "%s" successfully removed.'), $backend));
                $this->redirectNow('monitoring/config');
            } else {
                $this->render('show-configuration');
                return;
            }
        }

        $this->view->form = $form;
    }

    /**
     * Display a confirmation form to remove the instance identified by the 'instance' parameter
     */
    public function removeinstanceAction()
    {
        $config = $this->Config('instances');
        $form = new ConfirmRemovalForm(array(
            'onSuccess' => function ($request) use ($config) {
                $instanceName = $request->getQuery('instance');
                $configForm = new InstanceConfigForm();
                $configForm->setConfig($config);

                try {
                    $configForm->remove($instanceName);
                } catch (InvalidArgumentException $e) {
                    Notification::error($e->getMessage());
                    return;
                }

                if ($configForm->save()) {
                    Notification::success(sprintf(t('Instance "%s" successfully removed.'), $instanceName));
                } else {
                    return false;
                }
            }
        ));
        $form->setRedirectUrl('monitoring/config');
        $form->handleRequest();

        $this->view->form = $form;
    }

    /**
     * Display a form to edit the instance identified by the 'instance' parameter of the request
     */
    public function editinstanceAction()
    {
        $form = new InstanceConfigForm();
        $form->setConfig($this->Config('instances'));
        $form->setRedirectUrl('monitoring/config');
        $form->handleRequest();

        $this->view->form = $form;
    }

    /**
     * Display a form to create a new instance
     */
    public function createinstanceAction()
    {
        $form = new InstanceConfigForm();
        $form->setConfig($this->Config('instances'));
        $form->setRedirectUrl('monitoring/config');
        $form->handleRequest();

        $this->view->form = $form;
    }

    /**
     * Write configuration to an ini file
     *
     * @param   Zend_Config     $config     The configuration to write
     * @param   string          $file       The config file to write to
     *
     * @return  bool                        Whether the configuration was written or not
     */
    protected function writeConfiguration($config, $file = null)
    {
        if (is_array($config)) {
            $config = new Zend_Config($config);
        }
        $target = $this->Config($file)->getConfigFile();
        $writer = new PreservingIniWriter(array('filename' => $target, 'config' => $config));

        try {
            $writer->write();
        } catch (Exception $exc) {
            $this->view->exceptionMessage = $exc->getMessage();
            $this->view->iniConfigurationString = $writer->render();
            $this->view->file = $target;
            return false;
        }

        return true;
    }

    /**
     * Display a form to adjust security relevant settings
     */
    public function securityAction()
    {
        $form = new SecurityConfigForm();
        $form->setConfig($this->Config());
        $form->handleRequest();

        $this->view->form = $form;
        $this->view->tabs = $this->Module()->getConfigTabs()->activate('security');
    }
}
