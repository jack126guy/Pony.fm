angular.module('ponyfm').factory('playlists', [
	'$rootScope', '$state', '$http'
	($rootScope, $state, $http) ->
		playlistDef = null
		playlists = {}

		self =
			pinnedPlaylists: []

			fetch: (id, force) ->
				force = force || false
				return playlists[id] if !force && playlists[id]
				def = new $.Deferred()
				$http.get('/api/web/playlists/' + id).success (playlist) ->
					def.resolve playlist

				playlists[id] = def.promise()

			isPlaylistPinned: (id) ->
				_.find(self.pinnedPlaylists, (p) -> `p.id == id`) != undefined

			refreshOwned: (force) ->
				force = force || false
				return playlistDef if !force && playlistDef

				playlistDef = new $.Deferred()
				$http.get('/api/web/playlists/owned').success (playlists) ->
					playlistDef.resolve playlists

				playlistDef

			addTrackToPlaylist: (playlistId, trackId) ->
				def = new $.Deferred()
				$http.post('/api/web/playlists/' + playlistId + '/add-track', {track_id: trackId, _token: pfm.token}).success (res) ->
					def.resolve(res)

				def

			refresh: () ->
				$.getJSON('/api/web/playlists/pinned')
					.done (playlists) -> $rootScope.$apply ->
						self.pinnedPlaylists.length = 0
						self.pinnedPlaylists.push playlist for playlist in playlists

			deletePlaylist: (playlist) ->
				def = new $.Deferred()
				$.post('/api/web/playlists/delete/' + playlist.id, {_token: window.pfm.token})
					.then -> $rootScope.$apply ->
						if _.some(self.pinnedPlaylists, (p) -> p.id == playlist.id)
							currentIndex = _.indexOf(self.pinnedPlaylists, (t) -> t.id == playlist.id)
							self.pinnedPlaylists.splice currentIndex, 1

						if $state.is('playlist') && $state.params.id == playlist.id
							$state.transitionTo 'home'

						def.resolve()

				def

			editPlaylist: (playlist) ->
				def = new $.Deferred()
				playlist._token = pfm.token
				$.post('/api/web/playlists/edit/' + playlist.id, playlist)
					.done (res) ->
						$rootScope.$apply ->
							currentIndex = _.indexOf(self.pinnedPlaylists, (t) -> t.id == playlist.id)
							isPinned = _.some(self.pinnedPlaylists, (p) -> p.id == playlist.id)

							if res.is_pinned && !isPinned
								self.pinnedPlaylists.push res
								self.pinnedPlaylists.sort (left, right) -> left.title.localeCompare right.title
								currentIndex = _.indexOf(self.pinnedPlaylists, (t) -> t.id == playlist.id)
							else if !res.is_pinned && isPinned
								self.pinnedPlaylists.splice currentIndex, 1
								currentIndex = _.indexOf(self.pinnedPlaylists, (t) -> t.id == playlist.id)

							if res.is_pinned
								current = self.pinnedPlaylists[currentIndex]
								_.forEach res, (value, name) -> current[name] = value
								self.pinnedPlaylists.sort (left, right) -> left.title.localeCompare right.title

							def.resolve res
							$rootScope.$broadcast 'playlist-updated', res

					.fail (res)->
						$rootScope.$apply ->
							errors = {}
							_.each res.responseJSON.errors, (value, key) -> errors[key] = value.join ', '
							def.reject errors

				def

			createPlaylist: (playlist) ->
				def = new $.Deferred()
				playlist._token = pfm.token
				$.post('/api/web/playlists/create', playlist)
					.done (res) ->
						$rootScope.$apply ->
							if res.is_pinned
								self.pinnedPlaylists.push res
								self.pinnedPlaylists.sort (left, right) -> left.title.localeCompare right.title

							def.resolve res

					.fail (res)->
						$rootScope.$apply ->
							errors = {}
							_.each res.responseJSON.errors, (value, key) -> errors[key] = value.join ', '
							def.reject errors

				def

		self.refresh()
		self
])

