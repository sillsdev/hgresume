# hgresume #

Source code can be pulled from the Git repository located at:
https://github.com/sillsdev/hgresume

## Installation ##
1) Clone the hgresume repo to the htdocs directory on the apache server where you will be installing the resumable API
e.g. the repo base folder would be */var/www/[site_name]/htdocs*

2) Once you clone and update to the latest revision, you should have a folder structure like:
*/var/www/[site_name]/htdocs/api*

3) The api folder contains the PHP code for the API, while the contrib folder contains additional files needed for installation

4) The *api/[version]/src/config.php* file contains two variables that should be set to the directories that the API uses.
    * **CACHE_PATH** by default points to */var/cache/hgresume*
    * **REPOPATH** by default points to */var/vcs/public*

5) create the **CACHE_PATH** directory and change the ownership and permissions to www-data:www-data 750

6) verify that the **REPOPATH** directory is readable by www-data

7) the *RedmineResumable.pm* in the contrib folder can be referenced by the apache2 configuration in order to set up authentication.  An example of this is as follows:
    * In the `<VirtualHost>` element, have a PerlRequire directive that points to the RedmineResumable.pm in the contrib directory
    * Have a `<Location />` element inside the `<VirtualHost>` element that specifies the PerlAccessHandler and PerlAuthenHandler like this:
```
PerlAccessHandler Apache::Authn::RedmineResumable::access_handler
PerlAuthenHandler Apache::Authn::RedmineResumable::authen_handler

PerlSetVar dsn "DBI:mysql:database=blahblahblah"
PerlSetVar db_user username
PerlSetVar db_pass password

Require valid-user
```

8) Install Mercurial
```
sudo apt-get install mercurial
```

## Maintenance Mode ##
If there is ever a reason to shutdown the API temporarily, an admin or a server process can place a text file named maintenance_message.txt in the src directory with an appropriate explanation of why the API has been suspended.  All clients connecting to the API will receive the maintenance message.

## Recommended Development Environment ##

Our recommended development environment for web development is Linux Ubuntu GNOME.

## Local Linux Development Setup ##

Run the Ansible-assisted setup [described here](https://github.com/sillsdev/ops-devbox) to install and configure a basic development environment.

### Installation and Deployment ###

1. Clone this repo
```
mkdir src
cd src
git clone https://github.com/sillsdev/hgresume.git
```

2. Follow the [development setup instructions](https://github.com/sillsdev/web-languagedepot-api/blob/master/README.md#local-linux-development-setup) for **web-languagedepot-api**.

3. Enable **resumable.languagedepot.local**
```
sudo ln -s "$(pwd)/contrib" /var/www/languagedepot.org_resumable/contrib
sudo ln -s "$(pwd)/src" /var/www/languagedepot.org_resumable/htdocs/api/v03
sudo a2ensite languagedepot_org_resumable.conf
sudo service apache2 reload
```

## Testing ##

### PHP Unit Tests ###

Unit testing currently uses [PHPUnit](https://phpunit.de/) which was already installed by composer.

#### Integrating PHPUnit with PhpStorm ####

**File** -> **Settings** -> **Languages & Frameworks** -> **PHP** -> **PHPUnit**

Under PHPUnit Library, select `Use Composer autoloader` option
For `Path to script` browse to `hgresume/src/vendor/autoload.php`

Under Test Runner

Select *Default configuration file* and browse to `hgresume/test/phpunit.xml`

Select *Default bootstrap file* and browse to `hgresume/test/testconfig.php`

#### Running the tests ####
In a terminal, `gulp test-php-run`.  This will run the unit tests.

To test with debug info `gulp test-php-run --debug true`

To test with code coverage `gulp test-php-run --coverage true`.  
This will generate test coverage report in `test/CodeCoverage/index.html`. 

To run tests in PhpStorm, browse to the project view, right-click `test` folder and select `Run 'test'`.

## Updating dependencies ##
Occasionally developers need to update composer or npm:

#### Update npm packages ####

In the **root** folder: `npm install`

#### Update composer ####

In the **src** folder: `composer install`
