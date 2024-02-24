#!/bin/bash

# Default values
run_tests=0
image_path="$(pwd)"
LOCAL_PORT=""

# Help message
help_message() {
	echo "Usage: docker.sh [OPTIONS]"
	echo "Options:"
	echo "  --debug            Show debug information"
	echo "  --image-path       Path of where the images are. Default is '.'"
	echo "  --local-port       Local port to bind for the GUI"
	echo "  --run_tests        Run tests before starting"
	echo "  --help             Show this help message"
}

# Parse command-line arguments
while [[ "$#" -gt 0 ]]; do
	case $1 in
		--run_tests)
			run_tests=1
			shift
			;;
		--image-path)
			image_path="$(realpath $2)"
			shift
			;;
		--local-port)
			LOCAL_PORT="$2"
			shift
			;;
		--debug)
			set -x
			;;
		--help)
			help_message
			exit 0
			;;
		*)
			echo "Error: Unknown option '$1'. Use --help for usage."
			exit 1
			;;
	esac
	shift
done

if [[ ! -d "$image_path" ]]; then
	echo "$image_path is not a directory"
	exit 32
fi

echo "Using image path $image_path to mount into docker container."

echo "
version: '3'
services:
  simplephpimagegallery:
    build:
      context: .
    volumes:
      - $image_path:/docker_images/
" > docker-compose.custom.yml

# Check for required parameters
if [[ -z $LOCAL_PORT ]]; then
	echo "Error: Missing required parameter --local-port. Use --help for usage."
	exit 1
fi


is_package_installed() {
	dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -c "ok installed"
}

UPDATED_PACKAGES=0

# Check if Docker is installed
if ! command -v docker &>/dev/null; then
	echo "Docker not found. Installing Docker..."
	# Enable non-free repository
	sed -i 's/main$/main contrib non-free/g' /etc/apt/sources.list

	# Update package lists
	if [[ $UPDATED_PACKAGES == 0 ]]; then
		sudo apt update || {
			echo "apt-get update failed. Are you online?"
			exit 2
		}

		UPDATED_PACKAGES=1
	fi


	# Install Docker
	sudo apt install -y docker.io || {
		echo "sudo apt install -y docker.io failed"
		exit 3
	}
fi

if ! command -v wget &>/dev/null; then
	# Update package lists
	if [[ $UPDATED_PACKAGES == 0 ]]; then
		sudo apt update || {
			echo "apt-get update failed. Are you online?"
			exit 3
		}

		UPDATED_PACKAGES=1
	fi

	sudo apt-get install -y wget || {
		echo "sudo apt install -y wget failed"
		exit 3
	}
fi

if ! command -v git &>/dev/null; then
	# Update package lists
	if [[ $UPDATED_PACKAGES == 0 ]]; then
		sudo apt update || {
			echo "apt-get update failed. Are you online?"
			exit 3
		}

		UPDATED_PACKAGES=1
	fi

	sudo apt-get install -y git || {
		echo "sudo apt install -y git failed"
		exit 4
	}
fi

git rev-parse HEAD > git_hash

export LOCAL_PORT

# Write environment variables to .env file
echo "#!/bin/bash" > .env
echo "LOCAL_PORT=$LOCAL_PORT" >> .env

echo "=== Current git hash before auto-pulling ==="
git rev-parse HEAD
echo "=== Current git hash before auto-pulling ==="

git pull

function die {
	echo $1
	exit 1
}

SYNTAX_ERRORS=0
{ for i in $(ls *.php); do if ! php -l $i 2>&1; then SYNTAX_ERRORS=1; fi ; done } | 2>&1 grep -v mongodb

if [[ "$SYNTAX_ERRORS" -ne "0" ]]; then
	echo "Tests failed";
	exit 1
fi


if [[ "$run_tests" -eq "1" ]]; then
	php testing.php && echo "Syntax checks for PHP Ok" || die "Syntax Checks for PHP failed"
fi

sudo docker-compose build && docker-compose -f docker-compose.yml -f docker-compose.custom.yml up --build -d --remove-orphans || echo "Failed to build container"

rm git_hash
