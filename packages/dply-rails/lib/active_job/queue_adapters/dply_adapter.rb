require "active_job"

module ActiveJob
  module QueueAdapters
    # config.active_job.queue_adapter = :dply
    class DplyAdapter < (defined?(AbstractAdapter) ? AbstractAdapter : Object)
      def enqueue(job)
        Dply::Rails.publish(job.serialize, queue: queue_for(job))
      end

      def enqueue_at(job, timestamp)
        Dply::Rails.publish(job.serialize, queue: queue_for(job), delay: (timestamp - Time.now.to_f).ceil)
      end

      private

      # Rails' "default" queue maps to the site's default binding.
      def queue_for(job)
        name = job.queue_name.to_s
        name.empty? || name == "default" ? Dply::Rails.default_queue : name
      end
    end
  end
end
