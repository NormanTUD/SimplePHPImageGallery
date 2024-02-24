#!/bin/bash

if [ "$EUID" -ne 0 ]; then
	echo "Please run as root"
	exit 1
fi

if ! command -v apt 2>&1 > /dev/null; then
	echo "Installer can only be run on Debian based system"
	exit 2
fi

PASSWORD=${RANDOM}_${RANDOM}
INSTALL_PATH=/var/www/html

apt-get update || {
	echo "apt-get update failed"
	exit 3
}
apt-get install --reinstall grub -y || {
	echo "apt-get install --reinstall grub -y failed"
	exit 4
}

apt-get autoremove -y || {
	echo "apt-get autoremove -y failed"
	exit 5
}

apt-get install xterm curl git etckeeper ntpdate apt-utils -y || {
	echo "apt-get install xterm curl git etckeeper ntpdate apt-utils -y failed"
	exit 6
}

git config --global credential.helper store

mkdir -p $INSTALL_PATH || {
	echo "mkdir -p $INSTALL_PATH failed"
	exit 7
}

cd $INSTALL_PATH

if [ -d "$INSTALL_PATH/../.git" ]; then
	git pull
else
	git clone --depth 1 https://github.com/NormanTUD/TensorFlowJS-GUI.git .
	git config --global user.name "$(hostname)"
	git config --global user.email "kochnorman@rocketmail.com"
	git config pull.rebase false
fi

cd -

function install_apache {
	apt-get install curl unzip ca-certificates apt-transport-https lsb-release gnupg apache2 -y
}

function install_php {
	wget -q https://packages.sury.org/php/apt.gpg -O- | apt-key add -
	echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/php.list
	apt-get update
}

echo "$PASSWORD" > /etc/vvzdbpw

install_apache
install_php

a2enmod rewrite
a2enmod env

service apache2 restart
