<?php

namespace MediaWiki\Rest\Hook;

/**
 * Called by REST SearchHandler in order to allow extensions to fill the 'description'
 * field in search results. Warning: this hook as well as SearchResultPageIdentity interface
 * is being under development and still unstable.
 *
 * @unstable
 * @ingroup Hooks
 */
interface SearchResultProvideDescriptionHook {
	/**
	 * This hook is called when generating search results in order to fill the 'description'
	 * field in an extension.
	 *
	 * @since 1.35
	 *
	 * @param array $pageIdentities an array (string=>SearchResultPageIdentity) where key is pageId.
	 * @param array &$descriptions an output array (string=>string|null) where key
	 *   is pageId and value is either a desciption for given page or null
	 */
	public function onSearchResultProvideDescription( array $pageIdentities, &$descriptions );
}
