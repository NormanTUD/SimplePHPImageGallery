#!/bin/bash

if [ "$EUID" -ne 0 ]; then
	echo "Please run as root"
	exit 1
fi

if ! command -v apt 2>&1 > /dev/null; then
	echo "Installer can only be run on Debian based system"
	exit 2
fi

INSTALL_PATH=/var/www/html

mkdir -p $INSTALL_PATH || {
	echo "mkdir -p $INSTALL_PATH failed"
	exit 7
}

cd $INSTALL_PATH

a2enmod rewrite
a2enmod env

service apache2 restart
