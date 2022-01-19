#!/usr/bin/env python

from distutils.core import setup

main_ns = {}
with open('torqueclient/version.py') as ver_file:
    exec(ver_file.read(), main_ns)

with open("README.md", "r", encoding="utf-8") as readme:
    long_description = readme.read()

setup(
    name="torqueclient",
    # 0.1.1 was a necessary release due to an accident, and this method
    # should NOT be used in the future.  Instead, the __version__ from
    # the server and client should be synced up, and then a release should
    # happen.  Delete this comment when that happens.
    version="0.1.1",
    #version=main_ns['__version__'],
    description="Python client for mediawiki/torque",
    long_description=long_description,
    long_description_content_type="text/markdown",
    author="Open Tech Strategies, LLC",
    author_email="frankduncan@opentechstrategies.com", # For now, this works
    url="https://github.com/OpenTechStrategies/torque",
    classifiers=["Programming Language :: Python :: 3",
        "License :: OSI Approved :: GNU Affero General Public License v3",
        "Operating System :: OS Independent",
    ],
    packages=["torqueclient"],
    install_requires=["mwclient", "python-dateutil"],
    package_dir={"":  "."},
    python_requres=">=3.6",
)
