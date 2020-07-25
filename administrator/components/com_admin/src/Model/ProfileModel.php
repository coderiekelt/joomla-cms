<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Admin\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Users\Administrator\Model\UserModel;

/**
 * User model.
 *
 * @since  1.6
 */
class ProfileModel extends UserModel
{
	/**
	 * Method to auto-populate the state.
	 *
	 * @return  void
	 *
	 * @note    Calling getState in this method will result in recursion.
	 * @since   __DEPLOY_VERSION__
	 */
	protected function populateState()
	{
		parent::populateState();

		$this->setState('user.id', Factory::getApplication()->getIdentity()->id);
	}

	/**
	 * Method to get the record form.
	 *
	 * @param   array    $data      An optional array of data for the form to interrogate.
	 * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
	 *
	 * @return  Form  A Form object on success, false on failure
	 *
	 * @since   1.6
	 */
	public function getForm($data = [], $loadData = true)
	{
		// Get the form.
		$form = $this->loadForm('com_admin.profile', 'profile', ['control' => 'jform', 'load_data' => $loadData]);

		if (empty($form))
		{
			return false;
		}

		// Check for username compliance and parameter set
		$isUsernameCompliant = true;

		if ($this->loadFormData()->username)
		{
			$username = $this->loadFormData()->username;
			$isUsernameCompliant = !(preg_match('#[<>"\'%;()&\\\\]|\\.\\./#', $username) || strlen(utf8_decode($username)) < 2
				|| trim($username) != $username);
		}

		$this->setState('user.username.compliant', $isUsernameCompliant);

		if (!ComponentHelper::getParams('com_users')->get('change_login_name') && $isUsernameCompliant)
		{
			$form->setFieldAttribute('username', 'required', 'false');
			$form->setFieldAttribute('username', 'readonly', 'true');
			$form->setFieldAttribute('username', 'description', 'COM_ADMIN_PROFILE_FIELD_NOCHANGE_USERNAME_DESC');
		}

		// When multilanguage is set, a user's default site language should also be a Content Language
		if (Multilanguage::isEnabled())
		{
			$form->setFieldAttribute('language', 'type', 'frontend_language', 'params');
		}

		// If the user needs to change their password, mark the password fields as required
		if (Factory::getUser()->requireReset)
		{
			$form->setFieldAttribute('password', 'required', 'true');
			$form->setFieldAttribute('password2', 'required', 'true');
		}

		return $form;
	}

	/**
	 * Method to get the data that should be injected in the form.
	 *
	 * @return  mixed  The data for the form.
	 *
	 * @since   1.6
	 */
	protected function loadFormData()
	{
		// Check the session for previously entered form data.
		$data = Factory::getApplication()->getUserState('com_users.edit.user.data', []);

		if (empty($data))
		{
			$data = $this->getItem();
		}

		// Load the users plugins.
		PluginHelper::importPlugin('user');

		$this->preprocessData('com_admin.profile', $data);

		return $data;
	}

	/**
	 * Method to get a single record.
	 *
	 * @param   integer  $pk  The id of the primary key.
	 *
	 * @return  mixed  Object on success, false on failure.
	 *
	 * @since   1.6
	 */
	public function getItem($pk = null)
	{
		return parent::getItem(Factory::getUser()->id);
	}

	/**
	 * Method to save the form data.
	 *
	 * @param   array  $data  The form data.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.6
	 */
	public function save($data)
	{
		$user = Factory::getUser();
		$pk   = $user->id;

		unset($data['id'], $data['groups'], $data['sendEmail'], $data['block']);

		$isUsernameCompliant = $this->getState('user.username.compliant');

		if (!ComponentHelper::getParams('com_users')->get('change_login_name') && $isUsernameCompliant)
		{
			unset($data['username']);
		}

		// Handle the two factor authentication setup
		if (\array_key_exists('twofactor', $data))
		{
			$twoFactorMethod = $data['twofactor']['method'];

			// Get the current One Time Password (two factor auth) configuration
			$otpConfig = $this->getOtpConfig($pk);

			if ($twoFactorMethod !== 'none')
			{
				// Run the plugins
				PluginHelper::importPlugin('twofactorauth');
				$otpConfigReplies = Factory::getApplication()->triggerEvent('onUserTwofactorApplyConfiguration', [$twoFactorMethod]);

				// Look for a valid reply
				foreach ($otpConfigReplies as $reply)
				{
					if (!\is_object($reply) || empty($reply->method) || ($reply->method !== $twoFactorMethod))
					{
						continue;
					}

					$otpConfig->method = $reply->method;
					$otpConfig->config = $reply->config;

					break;
				}

				// Save OTP configuration.
				$this->setOtpConfig($pk, $otpConfig);

				// Generate one time emergency passwords if required (depleted or not set)
				if (empty($otpConfig->otep))
				{
					$oteps = $this->generateOteps($pk);
				}
			}
			else
			{
				$otpConfig->method = 'none';
				$otpConfig->config = [];
				$this->setOtpConfig($pk, $otpConfig);
			}

			// Unset the raw data
			unset($data['twofactor']);

			// Reload the user record with the updated OTP configuration
			$user->load($pk);
		}

		// Bind the data.
		if (!$user->bind($data))
		{
			$this->setError($user->getError());

			return false;
		}

		$user->groups = null;

		// Store the data.
		if (!$user->save())
		{
			$this->setError($user->getError());

			return false;
		}

		$this->setState('user.id', $user->id);

		return true;
	}
}
