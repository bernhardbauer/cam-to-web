# CAM to WEB

This library may be used to re-stream camera feeds to a web page. It has been written to support live streaming from Unifi UVC G3 and UVC G3 Dome cameras. As of writing this there has been no other easy and open implementation, which **does not require Flash or Java**, to get a RTSP stream displayed on a web page.



## Installation

1. Clone this project to a local work directory
2. Open a terminal and change into the directory of the downloaded project
3. Install composer using the [installation description provided by getcomposer.org](https://getcomposer.org/download/)
4. Run `composer install`
5. You may now start re-streaming videos. See the usage section for details about how to run the library



## Usage

 To start re-streaming open a terminal, change into the src directory and execute the command `./ReStream.php <input_rtsp_url> <feed_number>`. It retrieves the RTSP stream, sends it through a parsing script and creates a new WebSocket server on port 809<feed_number> which will be used to distribute the image data to the clients.
Change the port in index.html to match your feed number and open the file in your browser.



### Command Description

ReStream

* ./ReStream.php ReStream server
* <input_rtsp_url> The stream url. Must be an rtsp url and start with 'rtsp://'
* <feed_number> The feed number. Specifies on which port the websocket server will be opened (809<feed_number>). Must be an integer between (incl.) 0 and 9


FFMPEG

* `-rtsp_transport tcp` Use the TCP protocol to retrieve data from the RTSP url. If you are able to retrieve a stable stream over UDP this option may be omitted
* `-an` Disable Audio
* `-q:v 1` Specify the video quality. 1 = Highest Quality -> higher number = less image quality
* `-b:v 1000k` Output Bitrate (May be omitted)
* `-vsync 0` Only send new frames to the client (Do not send duplicate images)
* `-vf fps=fps=15` Set the frame rate to 15 frames per second
* `-hide_banner` Hides ffmpeg banner
* `-f image2` Specifies the format of the images which will be sent to the streaming script
