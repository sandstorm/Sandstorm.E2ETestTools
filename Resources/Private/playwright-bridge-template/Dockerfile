FROM mcr.microsoft.com/playwright:bionic

#
# THIS SCRIPT IS PART OF THE Sandstorm.E2ETestTools PACKAGE.
# It is COPIED to the respective project, so it can be adjusted to individual needs. However, the most up to date
# base version can always be found in the Sandstorm.E2ETestTools package.
#

RUN mkdir /app
WORKDIR /app
ADD package.json package-lock.json index.js /app/
RUN npm install
EXPOSE 3000
CMD ["node", "index.js"]
