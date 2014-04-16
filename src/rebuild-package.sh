#!/bin/sh

dir = `pwd`
deleteTo=`date +"%s"`

SCRIPT_PATH=$(dirname $(readlink -f $0))

. $SCRIPT_PATH/librc

unset GIT_DIR
unset GIT_WORK_DIR

: ${NAME=$1}
: ${VERBOSE="no"}
: ${TMPDIR="/var/tmp"}

: ${VERSION="`date "+%Y.%m.%d.%H.%M"`"}
: ${RELEASE="1"}

NAME=`basename $NAME`

if isnull $NAME; then
  echo "$0 packagename"
  exitf
fi

echo "Version: '$VERSION-$RELEASE'"

php deploy/releaseCheckRules.php $NAME
check=$?

if [ $check = 66 ]; then
  if [ "$2" = "--force" ]; then
	echo "skip warning by --force"
  else
	echo "Can't release, exiting..."
	exit $check
  fi
fi
echo "It's OK, building..."


srcdir=`printf %s/../build/%s $SCRIPT_PATH $NAME`
specfile=`printf %s/%s.spec $TMPDIR $NAME`


git clone ssh://git.whotrades.net/srv/git/phing-task $srcdir/phing-task
cd $srcdir/phing-task
git remote update
git checkout master
git reset --hard origin/master
git clean -f -d
cd $SCRIPT_PATH

ln -s  phing-task/build/$NAME/build.xml $srcdir/build.xml


cat <<EOF > $specfile || exit 1
%define __os_install_post %{nil}

Name:           $NAME
Version:        $VERSION
Release:        $RELEASE%{?dist}
Summary:        WhoTrades.com $NAME sources

Group:          Applications/WWW
License:        Proprietary
URL:            http://whotrades.com
BuildRoot:      %{_tmppath}/%{name}-%{version}-%{release}-root-%(%{__id_u} -n)
BuildArch:      noarch

BuildRequires:  php-pear-phing

%description
WhoTrades.Com: $NAME sources.

%prep


%build

%install
rm -rf %{buildroot}
mkdir -p %{buildroot}%{_localstatedir}/pkg/$NAME-$VERSION-$RELEASE
phing -Dname=$NAME -Ddestdir=%{buildroot}%{_localstatedir}/pkg/$NAME-$VERSION-$RELEASE -Ddictionary.sqlite.update=true -Drepositories.update=true -f $srcdir/build.xml build


%clean
rm -rf %{buildroot}


%files
%defattr(-,release,release,-)
%verify(not md5 mtime size) %{_localstatedir}/pkg/$NAME-$VERSION-$RELEASE


%changelog
* `date "+%a %b %d %Y"` Package Builder <vdm+release@whotrades.net> - $VERSION-$RELEASE
- RPM Package.
EOF



trap "{ rm -f $specfile; }" EXIT

rpmbuild="rpmbuild -ba $specfile"
if verbose; then
  rpmbuild="rpmbuild"
else
  rpmbuild="rpmbuild --quiet"
fi

$rpmbuild -ba $specfile
retval=$?

cd $dir

if [ $retval -eq 0 ]; then
  echo ${GREEN}$NAME $VERSION-$RELEASE${NORMAL}
  php deploy/releaseLogger.php $NAME $VERSION "built"
fi

exit $retval
