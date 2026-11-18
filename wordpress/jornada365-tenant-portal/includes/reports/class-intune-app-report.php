<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;
use J365TP\Graph_Throttled;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Common paging for Intune's tabular install reports. Column order is controlled by Graph. */
abstract class Intune_App_Report extends Report_Base {
	protected function app_params( array $params ): array {
		$id = $params['id'] ?? null;
		if ( ! is_string( $id ) || ! wp_is_uuid( $id ) ) {
			throw new \InvalidArgumentException( 'Selecione um aplicativo válido do catálogo do Intune.' );
		}
		return [
			'id'   => strtolower( $id ),
			'name' => mb_substr( sanitize_text_field( is_string( $params['name'] ?? null ) ? $params['name'] : '' ), 0, 200 ),
		];
	}

	/** Offset advances by the actual row count; Intune can return fewer rows than requested. */
	protected function install_pages( array &$state, array $query, bool $per_device, float $deadline, array $required, callable $collect ): bool {
		while ( ! $this->out_of_time( $deadline ) ) {
			$skip = $state['skip'] ?? 0;
			try {
				$page = $this->graph->intune_install_report( $query + [ 'skip' => $skip, 'top' => $per_device ? 50 : 100 ], $per_device );
			} catch ( Graph_Throttled $e ) {
				$this->pause_read( $state, $e->retry_after, $e->reason );
				return false;
			}
			$values = $page['Values'] ?? null;
			$total  = $page['TotalRowCount'] ?? null;
			$schema = $page['Schema'] ?? null;
			if ( ! is_array( $values ) || ! array_is_list( $values ) || ! is_array( $schema ) || ! array_is_list( $schema )
				|| ! is_scalar( $total ) || ! ctype_digit( (string) $total ) || ( ! $values && $skip < (int) $total ) ) {
				throw new Graph_Exception( 'O Intune devolveu uma página de relatório incompleta.', 502, 'intune_report_page' );
			}
			$columns = array_column( $schema, 'Column' );
			if ( count( $columns ) !== count( $schema ) || count( array_unique( $columns ) ) !== count( $columns ) || array_diff( $required, $columns ) ) {
				throw new Graph_Exception( 'Colunas necessárias ausentes no relatório do Intune.', 502, 'intune_report_schema' );
			}
			foreach ( $values as $record ) {
				if ( ! is_array( $record ) || ! array_is_list( $record ) || count( $columns ) !== count( $record ) ) {
					throw new Graph_Exception( 'O Intune devolveu uma página de relatório incompleta.', 502, 'intune_report_page' );
				}
				$collect( $state, array_combine( $columns, $record ) );
			}
			$state['skip'] = $skip + count( $values );
			if ( $state['skip'] >= (int) $total ) {
				return true;
			}
		}
		return false;
	}

	protected function progress( array $state, bool $done, int $n, string $message ): array {
		return [ 'state' => $state, 'done' => $done, 'progress' => $n, 'total' => $done ? $n : 0, 'message' => $done ? 'Concluído' : $message ];
	}
}
