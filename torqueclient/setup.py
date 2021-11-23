#!/usr/bin/env python

from distutils.core import setup

setup(
    name="torqueclient",
    version="1.0.0",
    description="Python client for mediawiki/torque",
    author="Open Tech Strategies, LLC",
    author_email="intentionally@left.blank.com",
    url="https://github.com/OpenTechStrategies/torque",
    packages=["torqueclient"],
    install_requires=["mwclient"],
)
