# frozen_string_literal: true

require "net/http"
require "uri"

module Dply
  module Rails
    # Short values on the attached key-value store. Required from lib/dply/rails.rb.
    # Rails.cache uses DplyStore when DPLY_KV_HOST is set and REDIS_URL is not.
    # User: "add support for key value store to dply/laravel ad dply/rails".
    module Kv
      module_function

      def host
        ENV.fetch("DPLY_KV_HOST", "")
      end

      def write(key, value, expires_in: nil)
        request(Net::HTTP::Put, key, value.to_s, expires_in)
        nil
      end

      def read(key)
        response = request(Net::HTTP::Get, key)
        response.body if response.is_a?(Net::HTTPSuccess)
      end

      def delete(key)
        request(Net::HTTP::Delete, key)
        nil
      end

      def request(klass, key, body = nil, expires_in = nil)
        raise "dply: DPLY_KV_HOST must be set" if host.empty?

        uri = URI("http://#{host}/#{key.to_s.sub(%r{\A/}, "")}")
        http = Net::HTTP.new(uri.host, uri.port)
        message = klass.new(uri)
        message["x-dply-ttl"] = expires_in.to_i.to_s if expires_in.to_i >= 60
        message.body = body unless body.nil?
        http.request(message)
      end
    end
  end
end

module ActiveSupport
  module Cache
    class DplyStore < Store
      def initialize(options = nil)
        super(options)
        @host = options.is_a?(Hash) ? options[:host] : nil
      end

      def read_entry(key, **_options)
        previous = ENV.fetch("DPLY_KV_HOST", nil)
        ENV["DPLY_KV_HOST"] = @host if @host
        body = Dply::Rails::Kv.read(key)
        body.nil? ? nil : Entry.new(body)
      ensure
        ENV["DPLY_KV_HOST"] = previous unless previous.nil?
      end

      def write_entry(key, entry, **options)
        previous = ENV.fetch("DPLY_KV_HOST", nil)
        ENV["DPLY_KV_HOST"] = @host if @host
        Dply::Rails::Kv.write(key, entry.value, expires_in: options[:expires_in])
        true
      ensure
        ENV["DPLY_KV_HOST"] = previous unless previous.nil?
      end

      def delete_entry(key, **_options)
        previous = ENV.fetch("DPLY_KV_HOST", nil)
        ENV["DPLY_KV_HOST"] = @host if @host
        Dply::Rails::Kv.delete(key)
        true
      ensure
        ENV["DPLY_KV_HOST"] = previous unless previous.nil?
      end
    end
  end
end
