<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2014 Leo Feyer
 *
 * @package Auth_client
 * @link    https://contao.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */


/**
 * Register the namespaces
 */
ClassLoader::addNamespaces(array
(
	'AuthClient',
));


/**
 * Register the classes
 */
ClassLoader::addClasses(array
(
	// Classes
	'AuthClient\ClcAuthProvider' => 'system/modules/auth_client/classes/ClcAuthProvider.php',
	'AuthClient\ClcPlusAuthProvider' => 'system/modules/auth_client/classes/ClcPlusAuthProvider.php',
	'AuthClient\LoginAuth'       => 'system/modules/auth_client/classes/LoginAuth.php',
	'AuthClient\AuthProvider'    => 'system/modules/auth_client/classes/AuthProvider.php',
	'AuthClient\tl_authclient_server'    => 'system/modules/auth_client/classes/tl_authclient_server.php',

	// widget
	'AuthClient\InfoboxField'    => 'system/modules/auth_client/widget/InfoboxField.php',


	// Models
	'AuthClient\AuthClientServerModel' => 'system/modules/auth_client/models/AuthClientServerModel.php',
));


/**
 * Register the templates
 */
TemplateLoader::addFiles(array
(
	'mod_authclient_serverlist' => 'system/modules/auth_client/templates',
));
