<?php

	namespace bbauer\CamToWeb\Server;

	class BroadcastServer extends WebSocketServer {

		protected function process($user, $message) {
			// Do nothing: This is a broadcast server -> clients can't send commands
		}

		protected function connected($user) {
			// Do nothing: This is a broadcast server
		}

		protected function closed ($user) {
			// Do nothing: Normally cleanup of open files, etc.
		}

	}
